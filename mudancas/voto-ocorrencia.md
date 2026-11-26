---
tipo: novidade
---
## Changelog
- Voto: o eleitor pode registrar uma ocorrência na votação pelo Portal do Eleitor (link discreto "Registrar ocorrência", janela de confirmação e formulário com a qualificação dele, texto de até 500 caracteres e limite de 5 por unidade em cada assembleia, com a votação aberta ou encerrada). Migration `Version20261126150000` cria a tabela `voto.ocorrencia_da_votacao` (assembleia, pauta opcional, unidade, nome e unidade congelados, texto e data).
- Voto: nova sub-aba "Ocorrências" na gestão da assembleia (só para quem gere) e seção "Ocorrências registradas" na ata e na minuta ("Nenhuma ocorrência registrada" quando não há).

## Para o cliente
- Quem está votando agora pode registrar uma ocorrência (por exemplo, um problema na reunião) por um link pequeno no fim da página. O sistema pergunta se a pessoa realmente quer registrar, mostra o nome e a unidade dela e pede um texto curto, de até 500 caracteres. Cada unidade pode registrar até 5 ocorrências por votação, tanto com a votação aberta quanto depois de encerrada.
- Quem cuida da votação vê todas as ocorrências na nova aba "Ocorrências" (data e hora, quem registrou, a votação a que se refere e o texto). Elas também aparecem na ata e na minuta, na seção "Ocorrências registradas".
