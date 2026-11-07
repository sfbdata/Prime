---
tipo: novidade
---
## Changelog
- Chat I.A: ferramenta de leitura `consultar_prazos_e_metas` (períodos hoje, próximos 7 dias e atrasadas; papéis da tela Minhas Metas). Reusa a consulta da tela (`TarefaRepository::findParaMinhasMetas` com cadeado e "só acompanhar não basta"), tira meta de outro escritório e de processo em segredo de justiça, e devolve o resultado numa segunda chamada sem ferramentas, em bloco `<prazos_e_metas>` neutralizado e mascarado. Oferecida só a quem tem o módulo Tarefas num escritório com o aceite de envio. Prompt `chat-livre-v5`. Spec `docs/specs/chat-ia-prazos-e-metas.md`.

## Para o cliente
- No chat da I.A., você pode perguntar o que vence hoje, nesta semana ou o que está atrasado. Ela responde só com os seus prazos e metas, os mesmos da tela Minhas Metas, e não cria nem altera nenhuma meta.
