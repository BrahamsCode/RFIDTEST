/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_URL: string;
  readonly VITE_REVERB_HOST: string;
  readonly VITE_REVERB_PORT: string;
  readonly VITE_REVERB_KEY: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
