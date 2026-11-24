---
tipo: novidade
---
## Changelog
- Rota `POST /perfil/ferramentas/{produto}` (`app_profile_ferramenta`, núcleo, `App\Produto\Controller\FerramentasDoEscritorioController`): corpo JSON `{ligar: bool}`, CSRF `perfil_ferramentas` no header `X-CSRF-Token`; responde `{ok, ligada}` ou `{erro}` com 403 (CSRF ou quem não é o dono), 404 (sem escritório na sessão ou ferramenta fora da lista) e 422 (corpo inválido, travada pelo Master, assembleia em andamento).
- `LigarFerramentaUseCase` e `DesligarFerramentaUseCase` (spec multiproduto, §9): só o dono (`DonoDoEscritorio`), antes de qualquer outra checagem. Ligar o Voto libera a `LiberacaoDoVoto` (cria se faltar); ligar a I.A. grava `liberarPeloDono()` e, só com orçamento nulo, `Incluso` com o teto `ia.tetoPadraoCentavos` da `configuracao_global` (reserva de 500); ligar o Expediente semeia os marcadores padrão se o escritório não tem nenhum. Desligar o Voto com assembleia em andamento é recusado; desligar o Voto bloqueia a liberação e desligar a I.A. chama `bloquearPeloMaster()` (o aceite fica).
- Serviço `ContratacoesDoEscritorio`: trava `pg_advisory_xact_lock(4801, tenant_id)`, materialização na primeira vez que algo é desligado num escritório sem linhas ou com `completo` (D5) e trava do Master (D16: última linha do produto encerrada com origem `master`; marcador aberto e encerrado no mesmo instante quando não havia linha ativa).
- Abas "Voto" e "I.A." do painel do Master: bloquear também encerra a contratação com origem `master` (o dono não religa); liberar reabre a contratação quando o produto não está incluído. Só a troca de estado mexe na contratação, com o estado lido dentro da trava do escritório. "Liberar todos" e "bloquear todos" passam, escritório por escritório, pelo mesmo caminho (na I.A., o modo e o teto de cada um ficam como estão).
- D7: o dono não desliga a última ferramenta ligada (422).
- Cobranças: o Judicializar na página do objeto passa a exigir também o Expediente, só com a chave `MULTIPRODUTO_MODO` ligada (`ProdutosNaTela`).
- Sem tabela, coluna nem migration nova. O cartão "Ferramentas do escritório" no Meu Perfil chega no próximo PR.

## Para o cliente
- Preparamos o BlueJus para o dono do escritório ligar e desligar sozinho as ferramentas que usa (Expediente, I.A., Cobranças, Cálculo, Kanban, Assinatura, Ponto e Voto). O botão no Meu Perfil chega na próxima versão; até lá, quem não mexer continua com as mesmas ferramentas de sempre.
- Desligar uma ferramenta não apaga nada: ao ligar de novo, tudo volta como estava. O Voto não desliga enquanto houver uma assembleia em andamento, e a última ferramenta ligada também não desliga.
- Quando a equipe do BlueJus desligar o Voto ou a I.A. de um escritório, só ela pode ligar de novo.
