---
tipo: alteracao
---
## Changelog
- Ponto Eletrônico, etapa E3 do desenho 16 (VS 08.11.26): a coluna da direita da /ponto ganha o cartão de abas "Pendências" e "Minhas Justificativas" no molde do desenho (partials novos `_abas*.html.twig`, `ponto-abas.js`), troca de aba sem recarregar. Os partials da tela antiga ficam intactos.
- Situações da justificativa com um nome só em todas as telas, inclusive a ficha do gestor e o sininho: "Em análise", "Abonado" e "Recusado"; na ficha, "Recusar" e "Motivo da recusa" (P40). Os textos passam por `NomesDasSituacoesDaJustificativa`, pronto para a chave do visual novo.
- Excluir a própria justificativa em análise (P41): `ExcluirJustificativaEmAnaliseUseCase`, só o dono, só em análise, só no escritório dela, com confirmação; o anexo sai depois do commit.
- Análise da própria justificativa (P43 a): só o primário ativo e o Master abonam, recusam, revertem ou lançam justificativa para si (`AnaliseDaPropriaJustificativa`); o gestor pelo perfil não vê os botões na própria e o servidor recusa com 403. A análise da justificativa de outra pessoa segue a regra de antes. Sem migration.

## Para o cliente
- No Ponto Eletrônico, as pendências de registro e as suas justificativas ficam num cartão com duas abas, ao lado da folha.
- Enquanto uma justificativa sua está em análise, você pode apagá-la. O sistema pede confirmação antes.
- As situações das justificativas têm os mesmos nomes em todas as telas e nos avisos: Em análise, Abonado e Recusado.
- O gestor da equipe não abona nem recusa a própria justificativa: ela fica para o administrador principal do escritório, que pode analisar também a dele. As justificativas da equipe seguem com os gestores, como antes.
