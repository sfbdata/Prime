---
tipo: novidade
---
## Changelog
- Trabalho Externo: Painel do banco de talentos, só do Master (F3b-1, spec §4.9). `GET /trabalho-externo/api/curriculos/painel` (`trabalho_externo_curriculos_painel`) devolve os 8 KPIs do desenho 06 (rótulos, ícones, cores, subtítulos e cliques), os 6 gráficos de barras (top 8, com o filtro de cada clique), as contagens por mês do crescimento (currículos, candidaturas e voluntários) e o número de inscritos. A Equipe, o externo e a prévia `?como=` recebem 403.
- Correções da §4.9: "ativos" (sem arquivados) é a base de todos os KPIs e barras de currículo; COM OAB conta e filtra advogado(a) e estagiário(a) com OAB (filtro `oab`, aceito também no CSV da lista); VOLUNTÁRIOS mostra "0 termo(s) aceito(s) · 0 aguardando" até o Termo de Voluntariado; a média de aceite das ofertas é a mesma do chip do banner.
- `GET /trabalho-externo/curriculos/alertas.csv` (`trabalho_externo_alertas_csv`, só Master): `alertas-vagas-bluejus.csv` com E-mail, UF, Áreas, Inscrito em e Origem, no formato das outras planilhas (BOM, `;`, aspas e proteção contra fórmula). Sem inscritos, 404 em JSON com o texto do toast "Ainda sem inscritos".
- Contadores anônimos da página pública, por dia (horário de Brasília) e por link: cada GET de `/vagas-juridicas/{token}` válido soma uma visita; `POST /vagas-juridicas/cta` (`vagas_publicas_cta`, 204, sem sessão nem CSRF, limitador `vagas_cta` de 30 por minuto por IP) soma um clique em "Criar conta". Robôs conhecidos e pedidos sem User-Agent não contam. Todo link de cadastro da página leva `data-url-cta`.
- Migration `Version20261019235900`: tabela `trabalho_externo_metrica_publica` (dia, link opcional com SET NULL, visitas, cliques; único por dia e `chave_do_link`), somada com `INSERT ... ON CONFLICT DO UPDATE`.

## Para o cliente
- O Master agora tem um Painel no banco de talentos: quantos currículos ativos, novos, com OAB e voluntários, vagas abertas e compartilhadas, ofertas aceitas, inscritos nos alertas por e-mail e quantas pessoas chegaram pelo link de vagas. Um clique em qualquer número ou barra abre a lista já filtrada.
