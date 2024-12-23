<h1 align="center" style="font-weight: bold;"> Sistema Estoque de Jogos 💻</h1>

<!-- <p align="center">
 <a href="#problema-e-contextualizacao">Problema e Contextualização</a> • 
 <a href="#tecnologias-utilizadas">Tecnologias Utilizadas</a> • 
 <a href="#primeiros-passos">Como Executar</a> •
 <a href="#apis-utilizadas">APIs Utilizadas</a> 
</p> -->

<p align="center">
    <strong>Sistema para controle e gerenciamento de estoque de jogos virtuais.</strong>
</p>

![Preview](image.png)

---

## 📝 Problema e Contextualização

Imagine gerenciar mais de 1000 jogos em estoque, acompanhando cada detalhe como:
valor pago,
lucro esperado,
plataforma de venda,
taxas aplicáveis,
e o lucro líquido após a venda.
Fazer isso manualmente, especialmente em grandes volumes, é desafiador, demorado e sujeito a erros.


### 💡 A Solução:
Um sistema completo para automação e controle dos jogos em estoque, eliminando a necessidade de planilhas e centralizando todas as informações em um banco de dados confiável. 

### ✅ Benefícios
1️⃣ Elimina o uso de planilhas extensas e propensas a erros.  
2️⃣ Automatiza cálculos complexos, reduzindo riscos de falhas humanas.  
3️⃣ Centraliza dados em um banco de dados seguro e acessível.  
4️⃣ Facilita consultas e abre portas para futuras automações, otimizando tempo e recursos.

### 🌟 Resultado:  
Mais eficiência, controle e tempo para focar nas atividades que realmente importam para o negócio!

### 🌐 Deploy

O sistema foi implementado em uma VPS, utilizando Nginx e PHP-FPM para gerenciar o servidor web, garantindo alta performance e estabilidade em produção. OBS: infelizmente não poderei compartilhar o link, pois o sistema possui dados privados.

---

## 💻 Tecnologias Utilizadas

Para o desenvolvimento do projeto, foram utilizadas as seguintes tecnologias:

- **Laravel/PHP** - Framework robusto para APIs RESTful e aplicações web escaláveis, seguindo o padrão MVC.
- **Inertia** - Criação de SPAs (Single Page Applications) unindo Laravel e Vue.js, eliminando APIs JSON intermediárias.
- **Socialite** - Autenticação via OAuth com Google já implementada.
- **Breeze** - Starter kit Laravel para autenticação básica, com templates prontos para front e back-end.
- **Vue.js** - Framework para desenvolvimento de interfaces dinâmicas e reativas no front-end.
- **TypeScript** - Superset do JavaScript que adiciona tipagem estática e recursos avançados como interfaces, generics e enums. Facilita a detecção de erros em tempo de compilação e melhora a manutenibilidade de projetos de grande escala, especialmente em front-ends complexos.
- **PostgreSQL** - Sistema de banco de dados relacional robusto e escalável, utilizado para armazenar dados de forma segura e eficiente.
- **Docker** - Plataforma para criação de containers que encapsulam aplicações e suas dependências, garantindo consistência entre os ambientes de desenvolvimento e produção. Docker Compose permite orquestrar múltiplos containers, como banco de dados, back-end e front-end, em um único arquivo de configuração YAML.
- **Nginx e PHP-FPM**: Configurados para gerenciar requisições de forma eficiente em uma VPS.
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

