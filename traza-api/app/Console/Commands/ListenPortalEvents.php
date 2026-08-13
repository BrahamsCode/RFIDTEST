<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\PortalEventService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\MqttClientException;
use PhpMqtt\Client\MqttClient;

/**
 * Suscriptor persistente del camino rápido del portal (`docs/07` §6).
 *
 * El evento de portal **no pasa por la cola de ingesta normal**: el
 * presupuesto extremo a extremo es de 800 ms y una cola con Horizon detrás
 * no lo cumple de forma fiable. Por eso hay un proceso propio, y por eso el
 * trabajo dentro del callback se limita a una consulta indexada y un insert.
 */
final class ListenPortalEvents extends Command
{
    protected $signature = 'traza:listen-portal
                            {--once : Procesa un único mensaje y termina; lo usa el diagnóstico}
                            {--timeout=0 : Segundos antes de salir; 0 = sin límite}';

    protected $description = 'Escucha traza/+/portal por MQTT y evalúa las alarmas antihurto';

    /** Con 2 s entre intentos son más de 60 años: en la práctica, nunca se rinde. */
    private const RECONNECT_FOREVER = 1_000_000_000;

    public function handle(PortalEventService $service): int
    {
        $client = new MqttClient(
            (string) config('mqtt.host'),
            (int) config('mqtt.port'),
            (string) config('mqtt.client_id').'-portal',
        );

        try {
            /*
             * Sesión persistente (clean = false) a propósito, y además la
             * librería no permite reconexión automática con sesión limpia.
             * Con sesión persistente el broker conserva la suscripción y
             * encola los mensajes QoS 1 mientras el proceso está caído: al
             * volver llegan todos. Se repetirá alguno, y es lo que se quiere
             * — perder una alarma es peor que registrarla dos veces.
             */
            $client->connect($this->connectionSettings(), false);
        } catch (MqttClientException $e) {
            $this->error("No se pudo conectar al broker MQTT: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Conectado a {$client->getHost()}:{$client->getPort()}. Suscrito a traza/+/portal.");

        $timeout = (int) $this->option('timeout');

        $client->subscribe(
            'traza/+/portal',
            function (string $topic, string $payload) use ($service, $client): void {
                $this->dispatchMessage($service, $topic, $payload);

                if ($this->option('once')) {
                    $client->interrupt();
                }
            },
            MqttClient::QOS_AT_LEAST_ONCE,
        );

        /*
         * Señal de vida para el healthcheck del contenedor. Este proceso es
         * el camino crítico de la alarma: si muere, las alarmas dejan de
         * sonar y nadie se entera hasta que roban algo. El fichero se toca
         * desde el propio bucle, así que solo se refresca si el bucle corre
         * de verdad, no si el proceso está vivo pero colgado.
         */
        $lastTouch = 0.0;

        $client->registerLoopEventHandler(
            function (MqttClient $c, float $elapsed) use ($timeout, &$lastTouch): void {
                if ($elapsed - $lastTouch >= 30.0 || $lastTouch === 0.0) {
                    $this->touchLivenessFile();
                    $lastTouch = $elapsed;
                }

                if ($timeout > 0 && $elapsed >= $timeout) {
                    $c->interrupt();
                }
            },
        );

        // Un SIGTERM de systemd o de Docker tiene que salir del bucle y
        // despedirse del broker; si el socket se corta a lo bruto, el broker
        // publica el last will y tarda en darse cuenta.
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $client->interrupt());
            pcntl_signal(SIGINT, fn () => $client->interrupt());
        }

        try {
            $client->loop(true);
        } finally {
            $client->disconnect();
        }

        $this->info('Suscriptor de portal cerrado.');

        return self::SUCCESS;
    }

    /**
     * Un mensaje malformado no puede tumbar el suscriptor: si cae, la tienda
     * se queda sin antihurto y nadie se entera hasta que roban algo.
     */
    public function dispatchMessage(PortalEventService $service, string $topic, string $payload): void
    {
        try {
            $data = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('Portal: carga MQTT ilegible', ['topic' => $topic, 'error' => $e->getMessage()]);

            return;
        }

        if (! is_array($data) || ! isset($data['epc'])) {
            Log::warning('Portal: mensaje sin epc', ['topic' => $topic]);

            return;
        }

        $device = $this->resolveDevice($topic, $data);

        if ($device === null) {
            Log::warning('Portal: dispositivo desconocido', [
                'topic' => $topic,
                'device_code' => $data['deviceCode'] ?? null,
            ]);

            return;
        }

        try {
            $event = $service->record($device, [
                'epc' => (string) $data['epc'],
                'direction' => (string) ($data['direction'] ?? 'indeterminado'),
                'confidence' => (float) ($data['confidence'] ?? 0.0),
                'occurredAt' => $data['occurredAt'] ?? null,
                'evidence' => is_array($data['evidence'] ?? null) ? $data['evidence'] : [],
            ]);

            // `output` es null cuando el método se llama fuera del comando,
            // que es como lo ejercitan las pruebas.
            if ($this->output?->isVerbose() === true) {
                $this->line(sprintf(
                    '%s %s conf=%.2f alarma=%s',
                    $event->epc,
                    $event->direction,
                    $event->confidence,
                    $event->alarm_raised ? 'sí' : 'no',
                ));
            }
        } catch (\Throwable $e) {
            Log::error('Portal: fallo al registrar el tránsito', [
                'topic' => $topic,
                'epc' => $data['epc'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * El tópico es `traza/{codigo_tienda}/portal`. Se prefiere el
     * `deviceCode` explícito del mensaje cuando viene, porque una tienda
     * puede tener más de un portal y la evidencia hay que atribuirla al que
     * la generó.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveDevice(string $topic, array $data): ?Device
    {
        if (isset($data['deviceCode'])) {
            $device = Device::query()->where('code', (string) $data['deviceCode'])->first();

            if ($device !== null) {
                return $device;
            }
        }

        $parts = explode('/', $topic);
        $locationCode = $parts[1] ?? null;

        if ($locationCode === null || $locationCode === '') {
            return null;
        }

        return Device::query()
            ->where('kind', 'lector_fijo')
            ->whereHas('location', fn ($q) => $q->where('code', $locationCode))
            ->orderBy('id')
            ->first();
    }

    /** Ruta que vigila el healthcheck de `infra/docker-compose.prod.yml`. */
    public const LIVENESS_FILE = '/tmp/portal-listener.alive';

    private function touchLivenessFile(): void
    {
        // Un fallo aquí no debe tumbar el suscriptor: como mucho el
        // orquestador reiniciará un proceso que en realidad iba bien.
        @touch(self::LIVENESS_FILE);
    }

    private function connectionSettings(): ConnectionSettings
    {
        // `MQTT_USERNAME=` en el .env llega como cadena vacía, y la librería
        // la rechaza por «espacios en blanco» en vez de tomarla por ausente.
        $username = config('mqtt.username') ?: null;
        $password = config('mqtt.password') ?: null;

        $settings = (new ConnectionSettings)
            ->setUsername($username)
            ->setPassword($password)
            ->setKeepAliveInterval(30)
            ->setConnectTimeout(5)
            // Reconexión automática: la conexión de una tienda de Gamarra se
            // cae varias veces al día y nadie va a reiniciar el proceso.
            // El límite no es 0 a propósito: la librería interpreta 0 como
            // «no intentarlo nunca» y devolvería sin conexión y sin error.
            ->setReconnectAutomatically(true)
            ->setMaxReconnectAttempts(self::RECONNECT_FOREVER)
            ->setDelayBetweenReconnectAttempts(2000);

        if (config('mqtt.tls')) {
            $settings = $settings
                ->setUseTls(true)
                ->setTlsCertificateAuthorityFile(config('mqtt.ca_file'))
                ->setTlsVerifyPeer(true);
        }

        return $settings;
    }
}
