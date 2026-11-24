---
tipo: novidade
---
## Changelog
- Aba "Ferramentas" no Painel do Master (`GET /master/produtos`, `master_produtos_dados`, e `POST /master/produtos/escritorios/{tenantId}`, `master_produtos_escritorio`, `App\Master\Controller\FerramentasDoMasterController`): grade dos escritórios ativos com as ferramentas de cada um (ligada, desligada, "desligada pelo BlueJus" quando travada). Corpo `{produto, ligado}` com CSRF `master_ferramentas` no header `X-CSRF-Token`; 403 (não Master ou CSRF), 404 (escritório inexistente, suspenso ou excluído) e 422 (produto fora da lista ou `ligado` que não é booleano).
- `AlterarFerramentaDoEscritorioPeloMasterUseCase` e `ObterFerramentasDosEscritoriosUseCase` (domínio Produto): o Master grava com origem `master`, auditoria no escritório alterado (`EscopoDeAuditoriaDePlataforma::executarComoConta`) e sob a trava das contratações. Desligar vira a trava da D16 e ligar a desfaz. `completo` pode ser posto e tirado. A D7 não vale para o Master. Voto e I.A. passam por `AlterarLiberacaoDoVotoUseCase` e `AlterarIaDoEscritorioUseCase::alterarLiberacao` (liberação e contratação andam juntas; ligar o Voto libera a `LiberacaoDoVoto`, D10).
- Ligar pelo Master termina sempre com a contratação ativa e desligar com a trava (origem `master`), mesmo com a liberação do Voto ou da I.A. já no valor pedido; ligar a I.A. com orçamento nulo grava `Incluso` com o teto padrão (D14); ligar o Expediente semeia os marcadores se faltarem.
- Teto padrão da I.A. na aba "I.A." do Master: `POST /master/ia/teto-padrao` (`master_ia_teto_padrao`, CSRF `master_ia`), `AlterarTetoPadraoDaIaUseCase`, `ConfiguracaoGlobal::definirTetoPadraoDaIa`/`getTetoPadraoDaIa` e o estado da aba com `tetoPadrao` (US$). Faixa de 0 a US$ 100.000,00 (fora dela, 422); a chave `ia.tetoPadraoCentavos` fica fora da tela de configurações. Vale só para quem o dono ligar depois: os tetos já gravados não mudam (D14).
- Sem tabela, coluna nem migration nova.

## Para o cliente
- Telas do Master: o BlueJus passou a ver e mudar, escritório por escritório, quais ferramentas cada escritório tem (Expediente, I.A., Cobranças, Cálculo, Kanban, Assinatura, Ponto e Voto), numa aba nova chamada Ferramentas.
- Quando a equipe do BlueJus desliga uma ferramenta de um escritório, o dono do escritório não consegue ligar de novo pelo Meu Perfil: ele vê "Desligada pelo BlueJus". Só a equipe do BlueJus liga de novo.
- Também ficou possível definir o limite mensal de gasto que a I.A. recebe quando o dono do escritório a liga sozinho. Quem já usa a I.A. mantém o limite que já tem.
