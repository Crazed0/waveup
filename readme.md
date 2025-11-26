# 🌊 wave-up.me

> “Uber” sobre água – reserva de viagens de barco com cálculo de rota, preço e muito mais (em construção).

[🇬🇧 English](#en) · [🇵🇹 Português](#pt)

---

<a id="en"></a>
## 🇬🇧 English

### 🚀 What is wave-up.me?

**wave-up.me** is a “water Uber” concept: a web platform to connect passengers and boat skippers for short trips, transfers and experiences on the water.

For this first prototype we focused on what we consider the **core of the product**:

- 🔐 A basic **register/login** flow  
- 🧭 A **route calculator** to estimate routes, trip time and pricing (**main part implemented**)  
- ⭐ An **evaluation system** (ratings/reviews) – currently **in progress**  

Everything else (landing page, recommended trips, fancy UI, etc.) is being built around this core.

> ⚠️ Disclaimer: This is a **work-in-progress / study project**, not a production-ready service.

---

### ✨ Current Features

- **Authentication**
  - User registration
  - Login / logout
  - Basic session handling

- **Route Calculator (Core)**
  - Origin and destination selection
  - Route calculation logic (prototype)
  - Trip estimate (distance / time / price – depending on configuration)
  - Designed to be extendable to different **services** (e.g. transfer, tour, taxi-boat)

- **Project Status**
  - Core auth + route calculator: ✅ first usable version
  - Evaluation/rating system: 🚧 in progress
  - Landing page & recommendations: 📝 planned
  - Additional cool stuff (filters, skipper dashboard, favorites…): 💡 ideas / backlog

---

### 🧩 Planned / Roadmap

Some of the things we are planning or experimenting with:

- 🏝 **Landing Page**
  - Highlighted / recommended trips
  - Quick booking flows
  - “Discover” section for popular routes

- ⭐ **Evaluation System**
  - Rate trips / skippers
  - Simple review text per trip
  - Aggregated ratings per skipper and per route

- 💼 **Extra Features**
  - Different service types (e.g. taxi, tour, private charter)
  - Favorites and saved routes
  - Admin / skipper dashboards

---

### 🛠 Tech Stack

> Adjust this section to match your actual implementation.

- **Frontend:** Next.js (React)  
- **Styling:** Tailwind CSS  
- **Backend / API:** Node.js (REST or Next.js API Routes)  
- **Auth:** Custom auth or library-based (e.g. JWT, NextAuth, etc.)  
- **Database:** (e.g. PostgreSQL / MySQL / MongoDB / SQLite)  

---

### 📦 Getting Started

> Example setup for a Node.js / Next.js project. Adapt if your setup is different.

#### 1. Clone the repository

```bash
git clone https://github.com/<your-username>/wave-up.me.git
cd wave-up.me
```

#### 2. Install dependencies

```bash
npm install
# or
yarn install
```

#### 3. Configure environment variables

Create a `.env.local` (or `.env`) file and add your environment variables, for example:

```bash
NEXT_PUBLIC_API_URL=http://localhost:3000
DATABASE_URL=...
JWT_SECRET=...
```

#### 4. Run the development server

```bash
npm run dev
# or
yarn dev
```

Open your browser at **http://localhost:3000**.

---

### 🧪 Scripts (examples)

```bash
npm run dev      # Start dev server
npm run build    # Production build
npm start        # Run production server
npm run lint     # Lint the codebase
```

---

### 🤝 Contributing

This project is still in an early stage, but feedback and ideas are welcome:

- Open an issue for bugs, suggestions, or feature requests  
- Fork the repo and submit a pull request  

---

### 📄 License

Define your license here (MIT, GPL, proprietary, etc.).

---

<a id="pt"></a>
## 🇵🇹 Português

### 🚀 O que é o wave-up.me?

**wave-up.me** é um conceito de “Uber da água”: uma plataforma web para ligar passageiros e skippers / proprietários de barco para viagens curtas, transferências e experiências na água.

Neste primeiro protótipo focámo-nos naquilo que consideramos o **núcleo do produto**:

- 🔐 Um fluxo básico de **registo/login**  
- 🧭 Um **calculador de rotas** para estimar percurso, tempo de viagem e preço (**parte principal implementada**)  
- ⭐ Um **sistema de avaliação** (ratings/reviews) – neste momento **em desenvolvimento**  

Tudo o resto (landing page, viagens recomendadas, UI mais polida, etc.) está a ser construído à volta deste núcleo.

> ⚠️ Aviso: Este é um projeto **em desenvolvimento / de estudo**, não é ainda um serviço pronto para produção.

---

### ✨ Funcionalidades atuais

- **Autenticação**
  - Registo de utilizadores
  - Login / logout
  - Gestão básica de sessão

- **Calculador de Rotas (Core)**
  - Seleção de origem e destino
  - Lógica de cálculo de rota (protótipo)
  - Estimativa de viagem (distância / tempo / preço – conforme configuração)
  - Pensado para suportar diferentes **tipos de serviço** (ex.: transfer, tour, táxi-barco)

- **Estado do Projeto**
  - Núcleo de auth + calculador de rotas: ✅ primeira versão utilizável
  - Sistema de avaliação: 🚧 em desenvolvimento
  - Landing page e recomendações: 📝 planeado
  - Outras funcionalidades (filtros, dashboard de skipper, favoritos…): 💡 ideias / backlog

---

### 🧩 Planeado / Roadmap

Algumas das coisas que estamos a planear ou a testar:

- 🏝 **Landing Page**
  - Viagens em destaque / recomendadas
  - Fluxos rápidos de reserva
  - Secção “Descobrir” para rotas populares

- ⭐ **Sistema de Avaliação**
  - Avaliar viagens / skippers
  - Comentário simples por viagem
  - Ratings agregados por skipper e por rota

- 💼 **Funcionalidades Extra**
  - Diferentes tipos de serviço (ex.: táxi, tour, charter privado)
  - Favoritos e rotas guardadas
  - Dashboards para admin / skippers

---

### 🛠 Stack Tecnológica

> Ajusta esta secção para corresponder ao que o projeto usa realmente.

- **Frontend:** Next.js (React)  
- **Estilos:** Tailwind CSS  
- **Backend / API:** Node.js (REST ou API Routes do Next.js)  
- **Auth:** Autenticação custom ou baseada em biblioteca (JWT, NextAuth, etc.)  
- **Base de Dados:** (ex.: PostgreSQL / MySQL / MongoDB / SQLite)  

---

### 📦 Como correr o projeto

> Exemplo para um projeto em Node.js / Next.js. Adapta conforme necessário.

#### 1. Clonar o repositório

```bash
git clone https://github.com/<o-teu-username>/wave-up.me.git
cd wave-up.me
```

#### 2. Instalar dependências

```bash
npm install
# ou
yarn install
```

#### 3. Configurar variáveis de ambiente

Cria um ficheiro `.env.local` (ou `.env`) e coloca as tuas variáveis, por exemplo:

```bash
NEXT_PUBLIC_API_URL=http://localhost:3000
DATABASE_URL=...
JWT_SECRET=...
```

#### 4. Correr em desenvolvimento

```bash
npm run dev
# ou
yarn dev
```

Depois abre **http://localhost:3000** no browser.

---

### 🧪 Scripts (exemplos)

```bash
npm run dev      # Arranca o servidor de desenvolvimento
npm run build    # Build para produção
npm start        # Correr o servidor em modo produção
npm run lint     # Lint ao código
```

---

### 🤝 Contribuições

O projeto ainda está numa fase inicial, mas qualquer feedback ou ideia é bem-vinda:

- Abre uma issue para bugs, sugestões ou pedidos de funcionalidades  
- Faz fork ao repositório e envia um pull request  

---

### 📄 Licença

Define aqui a licença do projeto (MIT, GPL, proprietária, etc.).
