# Relatório da Central de envios: estrela, arquivo, remoção, menu e exportação (F3e)

Desenho: `docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html`, marcação L300-305 (barra, abas,
linha) e L503-514 (visualizador e menu do botão direito), lógica L698-717. Continua a F3d
(`docs/specs/comercial-relatorio.md`, #209). Frente da 9b, `comercial-marcas`. Migration `Version20261020149000`
(reservada pelo coordenador). Risco BAIXO. Decisões do coordenador e da 9b em 21/10/2026.

## Estado da frente

- 21/10: spec; implementação começando.
- 21/10: implementado na branch `comercial-marcas` (commit local, sem PR): a tabela `comercial_campanha_marca`
  (migration 149000, entidade `CampanhaMarca`, `CampanhaMarcaRepository`), as abas Com estrela e Arquivadas com as
  contagens por Master, a estrela da linha, os botões da seleção, o menu do botão direito, "Reenviar a quem não leu"
  (só aviso) e "Exportar destinatários" (CSV pelo servidor, com a auditoria `camp_csv`). Rotas novas em
  `RelatorioDoMasterController`. "Encaminhar para um e-mail" ficou desabilitado no menu (regra 7, decisão do
  coordenador em 21/10).
- Desvios e preenchimentos: do visualizador, "Reenviar a quem não leu" segue o dc L713 (sem rastreio só avisa e o
  visualizador fica aberto; o aviso fica atrás dele, como no dc, e o `role="status"` o anuncia). As rotas com `{id}`
  aceitam até 9 dígitos (`RelatorioDoMasterController::ID`): acima do INT do Postgres seria 500, agora é 404. Se o
  POST das marcas falha, a lista recarrega para a tela e a seleção concordarem. O "Duplicar como
  nova" do menu busca a campanha pelo GET do visualizador (a linha não traz o título). A lixeira e o Excluir do menu
  gravam a mesma ação `remover`. O CSV traz uma linha por destinatário de cada execução (na recorrente, o mesmo e-mail
  aparece uma vez por execução, como no visualizador).

## Divisão com a F3g (0d)

A F3g (rastreio de leitura por campanha, migration 149100) é dona do `MetricasDaCampanha`, do trecho de leitura do
`comercial-relatorio.js` (a linha ✓/✓✓, a dica, os 2 KPIs de leitura, o texto do visualizador) e da coluna
`comercial_campanha.rastrear_leitura`. Esta frente não toca nesses pontos. Onde precisa saber se a campanha rastreou
leitura (o CSV e o "Reenviar a quem não leu"), usa hoje "não rastreada" fixo (o rastreio está desligado) e troca pelo
`RastreioDaLeitura::efetivo()` quando a F3g entrar. Quem entrar por último traz o master.

## Onde guarda: marcas por Master

Tabela `comercial_campanha_marca`: `campanha_id` (FK `comercial_campanha`, ON DELETE CASCADE), `user_id` (FK
`"user"`, ON DELETE CASCADE), `estrela` bool, `arquivada` bool, `removida_em` timestamp nulo, `alterada_em`; PK
(`campanha_id`, `user_id`). Cada Master tem as próprias marcas (no desenho elas ficam no navegador de quem marcou).
Sem linha = sem marca. Tabela da plataforma, sem tenant, como o resto do Comercial. Entidade Doctrine para o
`doctrine:schema:validate` ficar em dia; o acesso segue o padrão do Comercial (SQL na conexão DBAL).

**Remover do relatório** só esconde, para quem removeu (`removida_em`): nada da campanha nem dos envios é apagado.
Não há desfazer na tela (o desenho não tem).

## Regras

1. **Abas** (dc L702): Enviadas, Agendadas, Recorrentes, **Com estrela** ("Campanhas marcadas", `#c08a12`, `bi-star`)
   e **Arquivadas** ("Fora das listas", `#5b7383`, `bi-archive`). Arquivada sai das três primeiras e da estrela e só
   aparece em Arquivadas. Com estrela = estrela e não arquivada. Removida não aparece em aba nenhuma nem conta.
   Contagens como o dc: cada uma pela própria regra, sem a busca.
2. **Linha:** botão da estrela (`bi-star` / `bi-star-fill`) antes do público, que alterna a estrela daquela campanha
   sem abrir o visualizador. `title` da linha: "Abrir · botão direito para mais opções".
3. **Seleção** (barra, dc L301): com algo selecionado aparecem "Arquivar" (na aba Arquivadas, "Desarquivar"),
   "Estrela" (marca todas com estrela; title "Marcar com estrela") e o botão da lixeira (title "Remover do
   relatório", pergunta "Remover N campanha(s) do relatório?"). Depois da ação a seleção limpa e a lista recarrega.
4. **Menu do botão direito** na linha (dc L704), título = nome da campanha, fecha no clique fora, no Esc e no
   botão direito fora; itens e separadores na ordem do dc:
   - Abrir campanha (`bi-envelope-open`);
   - Duplicar como nova (`bi-files`): o mesmo evento `mc:duplicar-campanha` da F3d; aviso "Campanha duplicada:
     ajuste e envie.";
   - Reenviar a quem não leu (`bi-arrow-repeat`): só aviso. Sem rastreio: "Esta campanha não rastreou leitura.";
     com rastreio: "Reenvio disponível quando o servidor registrar as leituras.";
   - separador; Encaminhar para um e-mail (`bi-forward`): `prompt` "Encaminhar esta campanha (como teste) para:";
     aviso "Encaminhado para {e-mail}.";
   - Marcar com estrela / Remover estrela (`bi-star` / `bi-star-fill`); Arquivar / Desarquivar (`bi-archive`);
   - separador; Exportar destinatários (CSV) (`bi-filetype-csv`);
   - separador; Excluir do relatório (`bi-trash3`, cor `#a3232b`): pergunta "Excluir esta campanha do relatório?"
     (o mesmo "remover" da regra 3).
5. **Visualizador** (dc L503): ganha "Reenviar a quem não leu" (aviso; sem rastreio: "Esta campanha não rastreou
   leitura; não dá para saber quem não leu."; com rastreio: "Reenvio para quem não leu fica disponível assim que o
   servidor registrar as leituras.") e "Exportar destinatários", na ordem do dc (Duplicar, Reenviar, Exportar,
   Cancelar envio).
6. **Exportar destinatários (CSV):** pelo servidor (todos os destinatários, não só os 200 da tela), UTF-8 com BOM,
   separador `;`, arquivo `destinatarios-{id}.csv`. Do visualizador: `E-mail;Enviado;Leitura` (dc L708), Enviado
   `sim` quando o envio saiu (`enviado`, `falhou`, `incerto`) e `não` nos demais; Leitura `não rastreada` (fixo até a
   F3g). Do menu: só a coluna `E-mail` (dc L704). **Sem e-mail anonimizado** (o hash da retenção de 24 meses, sem
   `@`, fica de fora). Célula que começa com `=`, `+`, `-`, `@`, tab ou CR leva `'` na frente (injeção de fórmula).
   Cada exportação grava no `audit_log` (`camp_csv`, entidade `CampanhaDeEmail`, `changes` = {linhas, formato}, o
   Master como autor), sem os e-mails.
7. **Encaminhar para um e-mail: desabilitado** até o dono decidir (é a pergunta 1 da 5d: teste para qualquer
   endereço contraria a C4). O item aparece no menu, desabilitado, com o motivo no title. Sem rota de envio.
8. **Rotas** (todas `ROLE_SUPER_ADMIN`; POST com CSRF `master_comercial` no header `X-CSRF-Token`):
   - `GET /master/comercial/relatorio?aba=est|arq` (as abas novas na rota da F3d);
   - `POST /master/comercial/relatorio/marcas` com JSON `{ids: [..], acao: estrela|sem_estrela|arquivar|desarquivar|remover}`
     (até 50 ids; id inexistente é ignorado; ação inválida 422);
   - `GET /master/comercial/campanhas/{id}/destinatarios.csv?formato=completo|emails` (404 se não existe).

## Fora

- "Reenviar a quem não leu" de verdade (depende do rastreio, F3g e Política).
- Desfazer a remoção.
- "Encaminhar para um e-mail" de verdade (envio da campanha a um endereço digitado): espera a decisão do dono sobre
  a C4 (pergunta 1 da 5d).
- Limpeza do comentário solto em `RelatorioDeCampanhasRepository` (sobra da F3d, antes do `filtro()`): sai nesta
  frente.
