---
tipo: seguranca
---
## Changelog
- Meu Perfil, seção Segurança: botão "Sair de todos os aparelhos" (sem desenho; botão de contorno do próprio perfil e confirmação no modal do perfil). `POST /perfil/sair-de-todos-os-aparelhos` (`app_profile_sair_todos`, token `perfil_sair_todos`) chama o `SairDeTodosOsAparelhosUseCase`.
- O caso de uso grava o corte em `"user".sessoes_encerradas_em` (migration `Version20261105140000`, coluna nula) e apaga todas as séries de `rememberme_token` da pessoa na mesma transação, antes de ler a hora do corte (`ManterConectadoDoUsuario`, implementado por `EncerrarManterConectadoDoUsuario`). Assim um aparelho que voltou pelo cookie logo antes do DELETE também cai.
- A sessão guarda a hora da entrada (`_bj_entrou_em`, gravada no login pelo `MarcaDaSessaoNoLoginListener`). O `SessaoEncerradaEmTodosOsAparelhosListener` encerra no pedido seguinte a sessão que entrou até o segundo do corte: navegação vai ao /login com aviso, pedido de dados recebe 401 em JSON. A sessão de quem apertou o botão é marcada depois do corte e continua. As sessões ficam em arquivo, sem lista por usuário: por isso o corte mora no usuário.
- A senha não muda. Sessões abertas antes desta versão não têm a marca: só caem se a pessoa usar o botão.
- Testes que faltavam do teto de tentativas por conta (#652): a troca de senha pelo código do e-mail abre o login de novo, e no teto um e-mail que não existe recebe a mesma resposta da conta real.

## Para o cliente
- No Meu Perfil, em Segurança, há o botão "Sair de todos os aparelhos". Depois de confirmar, todos os outros computadores e celulares onde você entrou saem da sua conta, inclusive os que usam "Manter conectado". O aparelho onde você apertou continua conectado até você sair ou fechar o navegador; depois, entre com a senha. A senha não muda.
- Útil quando você entrou num computador de outra pessoa, perdeu o celular ou esqueceu a conta aberta em algum lugar.
- Conferimos com testes novos que, depois de muitas tentativas erradas, trocar a senha pelo código do e-mail libera a entrada de novo, e que a tela de login não revela se um e-mail tem conta.
