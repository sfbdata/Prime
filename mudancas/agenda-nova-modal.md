---
tipo: novidade
---
## Changelog
- Agenda nova (`/agenda/nova`): modal de criar e editar compromisso do desenho 09, com tipo (Compromisso, Audiência, Reunião, Prazo), dia inteiro, "Equipe do escritório" ou "Só eu", pasta pelo número, lembrete, repetição, participantes e "Avisar no Chat I.A" com @colega; pergunta "Compartilhar com a equipe?" ao criar em Minha agenda; "Avisar no Chat" e "Excluir" no detalhe.
- Agenda: endpoints `POST /agenda/compromissos/novo`, `/{id}/salvar`, `/{id}/excluir` e `/{id}/avisar`.
- Agenda: a entidade Evento passa ao domínio `App\Agenda\Entity`; o histórico da auditoria acompanha a troca de nome da classe.
- Banco: colunas `tipo`, `pasta_id`, `lembrete_minutos` e `avisados` na tabela `evento`.
