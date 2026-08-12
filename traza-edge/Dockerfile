FROM node:22-alpine AS base
WORKDIR /app
ENV NODE_ENV=development
COPY package*.json ./

FROM base AS development
RUN npm install
COPY . .
RUN mkdir -p /app/data
EXPOSE 9100
CMD ["npm", "run", "dev"]

FROM base AS build
RUN npm install
COPY . .
RUN npm run build

FROM node:22-alpine AS production
WORKDIR /app
ENV NODE_ENV=production
COPY package*.json ./
RUN npm install --omit=dev && npm cache clean --force
COPY --from=build /app/dist ./dist
RUN mkdir -p /app/data
EXPOSE 9100
CMD ["node", "dist/index.js"]
