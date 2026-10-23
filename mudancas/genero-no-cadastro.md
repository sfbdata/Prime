---
tipo: novidade
---
## Changelog
- Gênero obrigatório (Feminino ou Masculino) na criação da conta: cadastro da tela de entrada, aceite de convite do escritório e da plataforma sem conta e convite do Trabalho Externo. O perfil nasce com o gênero, sem migration.
- Janela "Como devemos tratar você?" no layout para quem está logado sem gênero preenchido (sem perfil, nulo ou o legado `nao_informado`); Salvar grava no próprio perfil (`POST /perfil/genero`, CSRF) e o X fecha só na sessão (`POST /perfil/genero/fechar`).
- "Prefiro não informar" saiu das opções em todo lugar, inclusive no Meu Perfil; o valor `nao_informado` fica no enum como legado e conta como não preenchido (decisão do dono de 23/10).
- Esteira de Minhas Metas: avisado com OAB no cadastro e gênero declarado aparece como "Dra. Nome" ou "Dr. Nome" (desenho 04, `trat()`), sem adivinhar o gênero pelo nome.

## Para o cliente
- Ao criar a conta, o sistema pergunta o seu gênero para escrever o seu nome corretamente (por exemplo, Dr. ou Dra.).
- Quem ainda não informou vê uma janela rápida para escolher; dá para fechar e responder depois.
- Na esteira de Minhas Metas, advogados aparecem com Dr. ou Dra. antes do nome.
