# Comercial F3d: Relatório como caixa de entrada (Central de envios)

Desenho: `docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html`, marcação L300-305 (barra, abas e
linhas) e L502-514 (visualizador), lógica L698-717. Spec-mãe: `comercial-e-emails.md` (§8.1.5 o freio, §8.4 e §8.5 a
campanha e os destinatários). Risco MÉDIO (envio em massa, dado pessoal de destinatário), só o Master.

## Estado da frente (21/10/2026; reler antes de continuar)

- **Branch:** `comercial-relatorio` (worktree `.claude/worktrees/comercial-relatorio`), saída do master. Sem migration.
- **Feito:** a lista com as 3 abas e a contagem, busca, paginação de 50 no SQL e seleção; a linha com selo, público e
  total, nome e subtítulo, leitura (✓/✓✓ com o % e o tooltip) e data; o visualizador com Voltar, Duplicar, Cancelar
  envio, os 4 KPIs, o HTML remontado e os destinatários com o estado. O bloco "Monitoramento" saiu (o
  `comercial-monitoramento.js` foi apagado); o `_comercial.html.twig` só ganhou o `include` (e perdeu a tag do JS
  antigo).
- **Combinado com a 5d (dona do resto do Comercial):** esta frente não toca `comercial-campanha.js`, o editor, os
  públicos, o confirmar e o teste. O estado `teste` em `comercial_campanha` entra pela 5d; o Relatório já o aceita
  (aba Enviadas, selo "Teste", "E-mail de teste" no visualizador).
- **Cancelar envio (freio):** também na fila, enviando e enviada com envio na fila, além de agendada e recorrente.
  Desvio do dc L711 aprovado pelo dono em 23/10 (branch `comercial-relatorio-freio`).
- **Pendente (fora desta fatia, depende de gravar no servidor):** "Com estrela" e "Arquivadas" (abas, botões da linha e
  da seleção), "Remover do relatório", o menu do botão direito, "Reenviar a quem não leu", "Exportar destinatários" e
  "Encaminhar para um e-mail". A seleção existe, mas ainda não tem ação.
- **Órfãos que ficaram (de propósito, para não mexer em arquivo da 5d):** a rota `GET /master/comercial/campanhas`
  (`master_comercial_campanhas`), o `ListarCampanhasUseCase`, o `CampanhaDaLista` e o CSS `.mc-mon`/`.mc-camp*` em
  `master-comercial.css`. Sem uso na tela; o teste `CampanhasDoMasterControllerTest::testListaDeCampanhas` ainda os
  cobre. Remover numa limpeza combinada com a 5d.

## Regras

1. **Abas.** Pelo estado: `recorrente` em Recorrentes, `agendada` em Agendadas, o resto (na fila, enviando, enviada,
   cancelada, teste) em Enviadas. A contagem de cada aba ignora a busca, como o dc.
2. **Busca.** No nome, no assunto, no modelo, no rótulo do público (como o dc L699; a chave gravada entra quando o
   rótulo de `PublicosDaCampanha::ROTULOS` contém a busca) e no e-mail de qualquer destinatário (`ILIKE`, com `%` e
   `_` literais), até 120 caracteres. Volta à página 1. A busca vai na URL (`GET ?q=`), como a busca rápida e as
   demais buscas do sistema; o e-mail buscado pode constar no access log do servidor, como nelas.
3. **Página.** 50 por página, `LIMIT/OFFSET` no SQL, da mais nova para a mais antiga; a página além do fim volta à
   última. A faixa é "1 a 50 de N" (o dc usa um traço entre os números; a regra do texto sem travessão o troca por "a").
4. **Métricas** (todas as execuções; uma consulta para a página inteira: `comercial_campanha_destino.envio_id` →
   `comercial_envio`):
   - Destinatários: os materializados; sem execução ainda, o total confirmado.
   - Enviados: envio `enviado`, `falhou` ou `incerto` (o que tentou sair).
   - Entregues: `RelatorioDeCampanhasRepository::CONDICAO_ENTREGUE` = situação `enviado` sem `erro` (decisão do
     coordenador, 21/10). Não há retorno do servidor de destino hoje; para mudar a regra, muda só essa constante.
   - Lidos: `primeira_abertura_em` preenchida. Não lidos: entregues menos lidos. Rejeitados: `falhou`.
   - Percentuais sobre os enviados (o dc divide pelo total enviado). Taxa de leitura sem envio: "0%".
   - Ícone: ✓ cinza (só enviado), ✓ verde (algum entregue), ✓✓ verde com o % (algum lido).
5. **Visualizador.** Remetente do `MAILER_FROM`; data agendada ou da confirmação; o HTML é
   `ComposicaoDaCampanha::previa(descongelar(campanha))` (o mesmo montador do envio, com "Dra. Ana" no `{nome}`), num
   iframe `sandbox=""` por `srcdoc`, e a resposta leva `Content-Security-Policy: sandbox`. Cópia que não valida mais:
   "Prévia não disponível para campanhas antigas." (texto do dc). Até 200 destinatários (o resto em "+ N destinatário(s)"); o e-mail
   anonimizado pela retenção de 24 meses aparece como "endereço anonimizado". Os e-mails só vão ao Master e nada vai
   para log.
6. **Cancelar envio.** Sobre o `EncerrarCampanhaUseCase`, que ganhou motivo e autor opcionais (padrão: "Encerrada pelo
   console", sem autor; o console não muda). Pela tela: "Cancelada pelo Master", com o Master na trilha `camp_fim`. O
   botão aparece onde o caso de uso aceita: estado ativo (na fila, agendada, enviando, recorrente) ou `enviada` com
   envio ainda na fila (`RelatorioDeCampanhasRepository::temEnvioNaFila`). A pergunta é "Cancelar o envio programado
   desta campanha?" na agendada e na recorrente, e "Cancelar o envio desta campanha? O que ainda está na fila não sai."
   nos demais (catálogo `comercial_relatorio.js.confirmar_cancelar` e `confirmar_cancelar_fila`). A rota aceita o que o caso de uso aceita (como o console). Encerrada ou inexistente: 422 com "Campanha não encontrada ou já encerrada.". CSRF
   `master_comercial` no header `X-CSRF-Token`.
7. **Duplicar.** Só no cliente. Dispara no `document` o evento cancelável `mc:duplicar-campanha` com
   `detail: { assunto, titulo, mensagem }` (o `[TESTE]` do começo do assunto sai). O editor que tratar o evento chama
   `preventDefault()`. Sem ninguém tratando, o Relatório preenche `[data-mc-cp-campo="assunto"]` e `"titulo"` com um
   evento `input` e rola até o editor.

## Rotas

| Rota | Nome | O quê |
|---|---|---|
| `GET /master/comercial/relatorio?aba=&q=&p=` | `master_comercial_relatorio` | `{aba, abas, total, pagina, paginas, faixa, campanhas}` |
| `GET /master/comercial/campanhas/{id}` | `master_comercial_campanha` | o visualizador; 404 se não existe |
| `POST /master/comercial/campanhas/{id}/cancelar` | `master_comercial_campanha_cancelar` | `{mensagem}`; 422 `{erro}`; 403 sem CSRF |

Todas com `#[IsGranted('ROLE_SUPER_ADMIN')]`, em `RelatorioDoMasterController`.

## Desvios e preenchimentos (onde o desenho é omisso)

- Botões de página anterior e próxima ao lado da faixa (o dc só mostra a faixa e a primeira página).
- Selos e textos dos estados que o protótipo não tinha: Enviando, Enviada, Cancelada.
- "Cancelar envio" também na fila, enviando e enviada com envio na fila (o dc L711 mostra só em agendada e recorrente).
  Desvio aprovado pelo dono em 23/10: o caso de uso aceita e é o freio do envio em massa. A pergunta muda nesses
  estados: "Cancelar o envio desta campanha? O que ainda está na fila não sai.".
- Tooltip da leitura pelo `title` nativo, com as linhas do dc.
- Destinatários: o estado vem do envio (na fila, enviado · aguardando leitura, lido, rejeitado, descadastrado,
  cancelado, envio incerto); antes de a campanha começar, "A lista é montada quando o envio começar.".
- Não rastreada: o dc tem "Leitura: não rastreada nesta campanha"; o sistema não guarda por campanha se o rastreio
  estava ligado, então a linha não aparece (sem rastreio, os lidos ficam em 0).
