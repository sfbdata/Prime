---
tipo: novidade
---
## Changelog
- Trabalho Externo (desenho 06 de 24/10, PR 1, sem migration): "Meu currículo" compacto (Editar e Word), com o cartão "Prévia do currículo" (o documento formatado e "Baixar PDF"); o cartão "Minhas candidaturas" sai da aba e o toast da candidatura fica só com "Candidatura enviada".
- Trabalho Externo: rota `GET /trabalho-externo/curriculos/meu/word` (`.doc` com BOM, "Currículo - {nome}.doc", só o próprio currículo), com a foto embutida e o logotipo com URL absoluta.
- Trabalho Externo: animação depois de enviar a solicitação (busca, parceiro e confirmação, com som e o mapa do Google da cidade da execução).
- Trabalho Externo: aviso novo `trabalho_externo_demanda_andamento` a quem pediu e ao convidado vigente a cada mudança de situação e registro da demanda (um por pessoa, sem quem agiu nem a Central; o convidado só recebe o que leria no chat). A tela aberta lê os novos por `GET /trabalho-externo/api/andamentos` e mostra "Andamento · {ID}", com um som só (o da Central, quando o lançador está na página).
- Trabalho Externo: "Alertas de vagas" no menu do Master liga e desliga o sininho das vagas; "Sua vaga · acompanhe os candidatos acima" no detalhe da própria vaga; dica "Desmarcar todas" no chat.

## Para o cliente
- No Trabalho Externo, "Meu currículo" ficou mais enxuto, com a prévia do currículo formatado e o botão para baixar em Word.
- Ao enviar uma solicitação, uma animação mostra a busca de correspondentes na cidade da execução.
- Quem pediu o serviço e o profissional convidado recebem um aviso a cada andamento da demanda.
