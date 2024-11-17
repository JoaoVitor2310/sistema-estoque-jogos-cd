<h1 align="center" style="font-weight: bold;"> Sistema Estoque de Jogos(em desenvolvimento) 💻</h1>

<p align="center">
 <a href="#problema-e-contextualizacao">Problema e Contextualização</a> • 
 <a href="#tecnologias-utilizadas">Tecnologias Utilizadas</a> • 
 <a href="#primeiros-passos">Como Executar</a> •
 <a href="#apis-utilizadas">APIs Utilizadas</a> 
</p>

<p align="center">
    <strong>Sistema para controle e gerenciamento de estoque de jogos virtuais.</strong>
</p>

![Preview](image.png)

---

## 📝 Problema e Contextualização

Imagine o seguinte cenário: você possui mais de 2000 jogos em estoque e precisa de controle total sobre cada um. É necessário acompanhar o valor pago, o lucro esperado, a plataforma em que o jogo foi anunciado, as taxas aplicáveis e o lucro real obtido após a venda. Gerenciar isso manualmente para um grande volume de jogos é complexo e demorado.

### Solução
Desenvolver um sistema web no qual o usuário insere apenas as informações essenciais, e o sistema realiza automaticamente todos os cálculos, incluindo taxas e lucros, de forma rápida e precisa.

### Benefícios
Esse sistema mantém os preços dos jogos sempre competitivos e atualizados, respeitando as regras do marketplace. Ele identifica preços muito baixos e monitora a concorrência que também usa automação de preços. Com isso, o vendedor reduz o trabalho manual e potencializa os lucros, mantendo seus produtos com os preços mais competitivos. Além disso, esses dados serão de suma importância para automações futuras.

---

## 💻 Tecnologias Utilizadas

Para o desenvolvimento do projeto, foram utilizadas as seguintes tecnologias:

- **Laravel/PHP** - Framework robusto para desenvolvimento de back-end, ideal para criação de APIs e aplicações web escaláveis.
- **Inertia, Socialite, Breeze** - Ferramentas para integração de front-end e back-end, autenticação de usuários e criação de interfaces simples e intuitivas.
- **Vue.js** - Framework JavaScript para construção de interfaces reativas no front-end.
- **TypeScript** - Linguagem que adiciona tipagem estática ao JavaScript, facilitando a manutenção e a segurança do código.
- **PostgreSQL** - Sistema de banco de dados relacional robusto e escalável, utilizado para armazenar dados de forma segura e eficiente.
- **Docker** - Plataforma de containers que permite a criação de ambientes isolados para o desenvolvimento e deploy.
- **Design Patterns (Singleton)** - Padrões de projeto para garantir organização, reusabilidade e eficiência no desenvolvimento.

---

## 🚀 Primeiros Passos

### Pré-requisitos

- [Docker](https://www.docker.com/): Instale o Docker para criar e gerenciar os containers da aplicação.

### Como executar:

1. Clone o repositório:
   ```bash
   git clone https://github.com/JoaoVitor2310/sistema-estoque-jogos
    ```
2. Entre no diretório do projeto:
   ```bash
   cd sistema-estoque-jogos
   ```
3. Crie uma cópia do arquivo de variáveis de ambiente:
   ```bash
   cp .env-example .env
   ```
4. Inicie os containers com o Docker Compose:
   ```bash
   docker compose up -d
   ```

### 🌐 Deploy

A aplicação está em produção em uma VPS e pode ser acessada pelo endereço: [http://191.101.70.89:100/](http://191.101.70.89:100/)