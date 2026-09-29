# Instruções para agentes (backend CLEISON)

- Leia `../docs/ARQUITETURA.md`, `../docs/MODELO-DE-DADOS.md` e `../docs/ETAPAS.md` antes de mudar algo.
- Não instale pacotes (inclusive Laravel Boost) sem pedido explícito do responsável pelo projeto.
- PostgreSQL é obrigatório; nunca troque para SQLite. Testes só no banco `*_teste`.
- Integridade da agenda mora no banco (constraints/triggers): não substitua por checagem na aplicação.
- Dinheiro em centavos (`integer`), instantes em `timestamptz`.
- Não crie usuários/senhas padrão nem dados "reais" inventados.
