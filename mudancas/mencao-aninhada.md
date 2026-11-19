---
tipo: correcao
---
## Changelog
- Menção aninhada no Registro, nas notas técnicas, nas observações e no despacho (`ResolucaoDeMencoesDoRegistro::resolver`). Num texto como `@[Fulano @[x](user:1)](user:6)`, só o token de dentro era conferido; trocá-lo fazia o de fora virar uma menção nova, gravada com um id que ninguém conferiu (inclusive de outro escritório). Agora a conferência se repete até o texto parar de mudar: o id de fora também passa pela lista de colegas ativos do escritório e, se não for um deles, vira texto comum. Ninguém de fora era avisado antes, e continua sem ser.
## Para o cliente
- Correção de segurança nas menções com @: uma menção escrita de um jeito incomum podia deixar gravada a marca de uma pessoa de outro escritório, que aparecia destacada no texto. Agora só fica destacada a menção a um colega ativo do próprio escritório; o resto vira texto comum.
