---
tipo: novidade
---
## Changelog
- BlueJus Voto: assembleia de condomínio (spec docs/specs/voto-assembleia-condominio.md, desenho 18). Migration `Version20261109170000` cria no schema `voto` as tabelas `assembleia`, `assembleia_pauta`, `assembleia_opcao`, `assembleia_unidade` e `assembleia_participacao` (FK de escritório com CASCADE, todas na purga do escritório). A chave `voto` continua desligada.
- Gestor (`/voto/assembleias`, rotas `voto_assembleia_*`, só com o escritório liberado no Master): criar a assembleia (peso por fração ideal ou uma unidade, um voto), pautas com opções numeradas e abstenção automática, importação da lista colada (unidade, bloco, situação, fração, responsável, e-mail; inadimplente fica inapta), publicar, links por unidade (copiar, mensagem para o WhatsApp, e-mail em lotes de 25, gerar novo link), mesa por pauta (1ª ou 2ª convocação, abrir por minutos ou sem prazo, definir prazo, encerrar com motivo; prazo vencido encerra sozinho), Acompanhar e Assistir (participação e quórum, nunca parcial), Resultados pela regra do desenho ("Decidido", "Quórum não atingido", "Empate", "Critério não atingido") e ata imprimível.
- Morador (`/votar/{token}`, público): cédula da unidade pelo link pessoal (token derivado por HMAC, só o hash no banco), um voto por pauta com confirmação, resultado depois do encerramento. Limitador `voto_cedula_invalido` (20/min por IP) só para link inválido; cabeçalhos noindex, no-store e no-referrer; escritório sem liberação dá 404.
- Sigilo prático: a participação (unidade, pauta, hora) e o voto ficam separados; o voto soma no contador da opção por SQL direto, tocando todas as opções da pauta na mesma transação, sem auditoria da opção nem da participação.
- Proteções: com peso por fração ideal, editar e publicar recusam unidade apta sem fração; o link por e-mail só vai para unidade apta (o reenvio para inapta é recusado); definir um prazo já vencido grava o encerramento e avisa; endereço de e-mail inválido conta como falha daquela unidade e o lote segue.
- A faixa "Ambiente demonstrativo" saiu de todas as abas de `/voto`.

## Para o cliente
- Novo no BlueJus Voto: votação de assembleia de condomínio pela internet. O escritório cria a assembleia, cola a lista de unidades com a fração ideal, cadastra as pautas e manda a cada unidade um link pessoal (por e-mail ou pelo WhatsApp). A mesa abre e encerra cada pauta na hora da assembleia, acompanha a participação e o quórum, e no fim tem o resultado com o peso de cada unidade e a ata pronta para imprimir. O voto é secreto: o sistema registra que a unidade votou, mas não em quê. Por enquanto só para os escritórios liberados pelo BlueJus.
- O sistema confere a lista antes de publicar: quando o voto vale pela fração ideal, toda unidade que pode votar precisa ter a fração preenchida. O link por e-mail só vai para as unidades que podem votar.
- A faixa de "ambiente de demonstração" saiu das telas do BlueJus Voto.
