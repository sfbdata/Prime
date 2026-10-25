---
tipo: novidade
---
## Changelog
- Trabalho Externo (desenho 06 de 24/10, D8, PR 2): DESPACHO no Resumo da demanda. Só o Master despacha: natureza (5 opções que desmarcam), prazo (padrão hoje mais 5 dias úteis, pulando sábado, domingo e os feriados nacionais do calendário do sistema), texto até 5.000 com contador, anexos (arquivos de verdade, baixáveis pela rota dos documentos e fora da lista DOCUMENTOS) e o registro "Novo despacho da Central BlueJus{ · natureza}." no chat, que avisa as partes.
- Trabalho Externo: chave "Aceitar resposta" do Master; com ela ligada e um despacho publicado, o solicitante e o convidado vigente respondem (até 3.000), com o registro "Resposta ao despacho de {quem}.".
- Trabalho Externo: lista numerada (o mais novo em cima), respostas recuadas em verde, "{Cidade}, {data por extenso}." e a assinatura eletrônica com o código; "Extrato" para impressão em `GET /trabalho-externo/demandas/{codigo}/despachos/extrato`.
- Rotas novas: `POST /trabalho-externo/api/demandas/{codigo}/despachos`, `.../despachos/respostas` e `.../despachos/aceitar-resposta`.
- Migration `Version20261024150500`: tabela `trabalho_externo_despacho`, `trabalho_externo_documento.despacho_id` e `trabalho_externo_demanda.respostas_ao_despacho_liberadas`.

## Para o cliente
- No Trabalho Externo, a Central BlueJus publica despachos no Resumo da demanda, com natureza, prazo e anexos, e pode liberar a resposta de quem pediu e do profissional. O extrato dos despachos sai pronto para imprimir.
