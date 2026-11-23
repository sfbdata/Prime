---
tipo: novidade
---
## Changelog
- Meu Perfil: cartão "Ferramentas do escritório" (`templates/profile/_ferramentas.html.twig`, spec multiproduto §9, PR 8b), só para o dono do escritório da sessão (função Twig `ferramentas_do_escritorio()`, `ListarFerramentasDoEscritorioUseCase`). Cada ferramenta aparece ligada, desligada, "Desligada pelo BlueJus" (trava do Master, D16) ou "Indisponível no momento" (chave global do Voto ou plataforma da I.A.); o Voto e a I.A. só aparecem ligados com a liberação de hoje.
- Os botões chamam `POST /perfil/ferramentas/{produto}` (PR 8a) e recarregam a página; ao ligar, aparece o aviso para ajustar o acesso em Perfis (regra 11). A I.A. ligada sem o aceite mostra o link para a página do aceite (regra 4).
- `ContratacoesDoEscritorio::travadosPeloMaster()` (uma consulta para o cartão inteiro) e `CatalogoDeProdutos::icone()`.
- Sem tabela, coluna nem migration.

## Para o cliente
- O dono do escritório ganhou, no Meu Perfil, o cartão "Ferramentas do escritório": ali ele liga e desliga sozinho o Expediente, a I.A., as Cobranças, o Cálculo, o Kanban, a Assinatura, o Ponto e o Voto.
- Desligar não apaga nada: ao ligar de novo, tudo volta como estava. O menu muda na hora.
- Ao ligar uma ferramenta, o cartão lembra de ajustar o acesso nos Perfis da equipe, porque os perfis criados pelo escritório não ganham acesso sozinhos.
- Quando a equipe do BlueJus desligar uma ferramenta, ela aparece como "Desligada pelo BlueJus" e só a equipe liga de novo.
