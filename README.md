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

Imagine o seguinte cenário: você possui mais de 2000 jogos em estoque e precisa de controle total sobre cada um. É necessário acompanhar o valor pago, o lucro esperado, a plataforma em que o jogo será anunciado, as taxas aplicáveis, o lucro  após a venda e etc. Gerenciar isso manualmente para um grande volume de jogos é complexo e demorado.

### Solução
Desenvolver um sistema web no qual o usuário insere apenas as informações essenciais, e o sistema realiza automaticamente todos os cálculos como taxas e lucros, de forma rápida e precisa. Além de 

### Benefícios
O principal benefício é eliminar a necessidade de lidar com planilhas extensas e frequentemente problemáticas. O sistema oferece ao usuário maior controle sobre as informações dos jogos, automatizando cálculos complexos e reduzindo significativamente o risco de erros humanos. Além disso, ao consolidar todos os dados em um banco de dados centralizado, as informações tornam-se mais acessíveis e fáceis de gerenciar. Isso não apenas simplifica consultas e manipulações futuras, como também abre espaço para a implementação de novas automações, permitindo ao usuário dedicar mais tempo às atividades que realmente importam.

---

## 💻 Tecnologias Utilizadas

Para o desenvolvimento do projeto, foram utilizadas as seguintes tecnologias:

- **Laravel/PHP** - Framework PHP robusto, que segue o padrão MVC (Model-View-Controller) e oferece uma estrutura bem definida para o desenvolvimento de APIs RESTful e aplicações web escaláveis. Inclui suporte nativo a middleware, autenticação, filas, jobs e Eloquent ORM para integração com bancos de dados relacionais.
- **Inertia** - Permite construir aplicações SPA (Single Page Applications) usando Laravel no back-end e frameworks modernos como Vue.js no front-end, eliminando a necessidade de APIs JSON intermediárias.
- **Socialite** - Biblioteca integrada ao Laravel para autenticação de usuários por provedores OAuth (como Google(implementado), Facebook e GitHub), simplificando o login social.
- **Breeze** - Starter kit leve para Laravel, que implementa autenticação básica (login, registro e recuperação de senha) com um front-end minimalista em Blade, Vue.js ou React, otimizando o início de projetos.
- **Vue.js** - Framework baseado em JavaScript para desenvolvimento de interfaces reativas no front-end. Suporta componentes reutilizáveis, comunicação eficiente via props e events.
- **TypeScript** - Superset do JavaScript que adiciona tipagem estática e recursos avançados como interfaces, generics e enums. Facilita a detecção de erros em tempo de compilação e melhora a manutenibilidade de projetos de grande escala, especialmente em front-ends complexos.
- **PostgreSQL** - Sistema de banco de dados relacional robusto e escalável, utilizado para armazenar dados de forma segura e eficiente.
- **Docker** - Plataforma para criação de containers que encapsulam aplicações e suas dependências, garantindo consistência entre os ambientes de desenvolvimento e produção. Docker Compose permite orquestrar múltiplos containers, como banco de dados, back-end e front-end, em um único arquivo de configuração YAML.
- **Design Patterns (Singleton)** - Padrão de projeto que garante a existência de apenas uma instância de uma classe durante o ciclo de vida da aplicação. Comumente usado para gerenciar conexões com banco de dados, cache ou serviços globais em APIs e aplicações web, evitando redundâncias e melhorando o uso de recursos.

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
4. Edite o arquivo env com os seus dados:
   ```bash
   nano .env
   ```
5. Inicie os containers com o Docker Compose:
   ```bash
   docker compose up -d
   ```

### 🌐 Deploy

A aplicação está em produção em uma VPS utilizando o docker e pode ser acessada pelo endereço: [http://191.101.70.89:100/](http://191.101.70.89:100/)
