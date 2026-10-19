---
tipo: novidade
---
## Changelog
- Aba "Minha conta" no modal "Conta do escritório" (desenho bj-contas.js, VS 18.10.26), só para o usuário primário ou o Master: equipe da conta com PRIMÁRIO/SECUNDÁRIO e a situação de cada pessoa (APROVADO, EM ANÁLISE, SUSPENSO), menu pelo ⋮ ou botão direito para aprovar, voltar para análise, suspender o acesso e demitir (a mesma remoção da tela de equipe), e o formulário de convite. "Convites do escritório" no menu e nas abas do Meu Perfil abre essa aba.
- Coluna nova user_tenant.situacao (migration Version20261019134246); quem está em análise ou suspenso não entra no escritório e vê o motivo na tela de seleção. O acesso passa a conferir as barreiras em ordem (escritório, pedido de exclusão, vínculo, situação). O primário e o Master não têm a situação alterada.

## Para o cliente
- O usuário primário agora vê a equipe do escritório numa janela só e pode suspender ou reativar o acesso de cada pessoa, sem apagar os dados dela.
