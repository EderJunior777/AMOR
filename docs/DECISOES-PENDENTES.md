# Decisões pendentes (do negócio)

Nada abaixo foi inventado como dado real: onde o sistema precisou de um
valor, usou o do ZIP **no seed de demonstração** ou um padrão conservador
documentado.

## Identidade e dados reais
1. **Nome, marca, endereço, WhatsApp, Instagram** reais do Cleison. Hoje só
   existe "Barbearia do Ze (demonstracao)" no seed.
2. **Serviços, preços e durações reais** (o seed usa os do ZIP).
3. **Horário real**: dias, abertura/fechamento, almoço, feriados. O ZIP diz
   08:00–20:00, todos os dias.
4. **Regiões atendidas, deslocamento e taxa** (o ZIP tem taxa única de R$ 20;
   o modelo aceita taxa por região).
5. **Existem dados reais nos Blobs da Netlify?** Quem tem acesso à conta?

## Agenda
6. **Contagem de cortes**: o seed marca como corte: Corte, Corte + Barba,
   Degradê e Infantil. **Barba e Pezinho não contam.** Confirmar.
7. **Combo corte + barba**: continua um serviço único (conta 1 corte, preço
   do combo) ou vira dois itens?
8. **Grade** de 30 min e **arredondamento do deslocamento** para a grade
   (herdado do site). Manter?
9. **Antecedência mínima** (30 min) e **horizonte** (30 dias).
10. **Expiração de solicitações não confirmadas**: hoje nenhuma expira. Se
    quiser expirar, definir prazo e quem é avisado (exige tarefa agendada
    monitorada).
11. **Cancelamento/remarcação pelo cliente**: permitido? Até quanto tempo
    antes?
12. **Falta marcada por engano**: permitir "desfazer falta" (com permissão e
    trilha)? Hoje `nao_compareceu` é encerrado.
13. Quantos **profissionais** na largada? Todos fazem domicílio?

## Acesso e segurança
14. Verificação do cliente por **WhatsApp** (API oficial da Meta, número
    dedicado) ou outro canal (SMS/e-mail)? Sem isso, o cliente não consulta
    histórico online.
15. **Perfis**: haverá recepção? Barbeiro vê o telefone/endereço só dos
    próprios clientes?
16. **Retenção e LGPD**: pedido de exclusão = anonimizar, mantendo o
    histórico (decidido e implementado, `docs/LGPD-ANONIMIZACAO.md`). **Ainda
    em aberto (D5):** por quanto tempo guardar clientes inativos. Decisão do
    responsável + jurídico; o parâmetro `CLEISON_RETENCAO_CLIENTE_INATIVO_MESES`
    não tem valor padrão e, sem ele, a retenção automática não roda.
17. Método de **MFA**: TOTP (app autenticador) é o proposto.

## Recebimentos e fechamento (etapas 4–5)
18. Quem pode registrar recebimento, dar desconto e registrar devolução?
19. Canal "externo": o que significa na operação real?
20. Limite de desconto sem aprovação?

## Infraestrutura (etapa 6)
21. **Hospedagem** PHP/PostgreSQL (VPS, PaaS?), domínio, orçamento.
22. Política de **backup** (frequência, retenção, onde) e quem ensaia a
    restauração.
23. Docker para desenvolvimento em Linux/macOS: não entregue, porque não pôde
    ser verificado nesta máquina.

## Surgidas na revisão da etapa 1

24. **Correção de atendimento encerrado.** Hoje o banco não deixa alterar nada
    de um agendamento concluído, cancelado ou com falta, nem seus itens (para
    proteger o histórico). Se a operação precisar corrigir um erro (ex.: serviço
    lançado errado num corte já concluído), qual o fluxo? Proposta: correção
    como novo registro com trilha (etapa 4), nunca edição silenciosa.
25. **Falta marcada por engano** (repetição do item 12): com a imutabilidade,
    desfazer exige uma regra explícita.
26. **Folgas e férias** são bloqueios na agenda (o banco impede criar férias
    por cima de agendamentos existentes: eles precisam ser remarcados ou
    cancelados antes). Confirmar que esse é o comportamento desejado.
27. **Arredondamento do deslocamento para a grade** (Centro, 15 min, ocupa 30
    de cada lado): manter? A validação do expediente (etapa 2) considerará o
    período ocupado já arredondado.
28. **Exceções de expediente**: numa data com exceção, as janelas da exceção
    substituem as semanais por completo. Fechar o dia inteiro é feito com
    bloqueio (folga/feriado), não com exceção. Confirmar.
29. **Papéis do banco em produção**: a hospedagem escolhida precisa permitir
    dois papéis (dono do schema e aplicação sem DDL). Alguns PostgreSQL
    gerenciados impõem papéis próprios; verificar antes de contratar.
