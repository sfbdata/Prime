---
tipo: novidade
---
## Changelog
- Trabalho Externo: candidatura às vagas (F2b, spec §4.6). O usuário externo com currículo se candidata ("Recebida", com "Candidatura de {nome}" no histórico da vaga); sem currículo, a tela abre o formulário e "Salvar e candidatar-se" (`candidatarA` em `POST /api/curriculos/meu`). O servidor recusa o interno, a vaga própria, a vaga fechada e o prazo de inscrição vencido.
- Aceite da oferta com tempo sob trava da linha da vaga (`SELECT ... FOR UPDATE`): nasce "Aprovado", conta os aceites da rodada atual contra a quantidade e, ao completar, a vaga fica preenchida com o tempo da rodada. "Ofertar novamente" e "Reabrir" contam do zero. Aceite em vaga de escritório entra direto nos enviados.
- Central com `talentos`: muda o andamento do candidato (Recebida, Em análise, Entrevista, Aprovado, Contratado, Não selecionado) e envia candidatos ao escritório dono da vaga. O dono vê só os enviados e abre o currículo deles sem a nota interna, sem a situação no banco e sem as outras candidaturas.
- Linha do tempo da candidatura, compatibilidade com o currículo (ordem das vagas do externo e selo "N% compatível"), "Minhas candidaturas" com marcações pessoais, e importância das vagas e dos currículos com as candidaturas.
- Rotas novas: `trabalho_externo_vaga_candidatura`, `trabalho_externo_vaga_enviar`, `trabalho_externo_candidatura_status`, `trabalho_externo_candidaturas_marcacoes`. Migration `Version20261019231700`: tabela `trabalho_externo_candidatura` (FKs CASCADE para vaga e currículo) e `rodada`, `inicio_da_rodada` na vaga.

## Para o cliente
- Agora dá para se candidatar às vagas da Central e aceitar ofertas relâmpago com um clique, acompanhando cada etapa em "Minhas candidaturas". A Central analisa os candidatos e envia os melhores ao escritório que divulgou a vaga.
