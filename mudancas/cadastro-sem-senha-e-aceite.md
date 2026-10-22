---
tipo: seguranca
---
## Changelog
- Cadastro: o passo "Criar conta" da tela de entrada não pede mais a senha nem mostra a régua de força; a senha é criada no link de confirmação do e-mail. O servidor continua ignorando `senha`, se um cliente antigo ainda mandar.
- Cadastro: a página "Crie sua senha" do link de confirmação pede a caixa "Li e aceito os Termos de uso e a Política de privacidade" (obrigatória; sem ela, a mensagem do cadastro e o link continua valendo) e grava, na mesma transação da senha e só para quem ganha a corrida, um aceite novo dos Termos e a ciência da Política (versão vigente, IP, user agent, escritório de que a pessoa é dona). Os do cadastro ficam.
- Termos: `aceite_termo` e `ciencia_politica` ganham a coluna `origem` (`cadastro`, `link_confirmacao`, `reaceite`, `cadastro_nao_ratificado`; as linhas existentes ficam `cadastro`), e o índice único passa a (user_id, versao, origem). O gate dos Termos grava `reaceite`. Migration `Version20261020149400`, com DROP INDEX: deploy MANUAL, aplicada pelo dono.
- Senha: a conta a confirmar que é confirmada pelo código do "Esqueceu a senha?" perde o valor do aceite dos Termos e da ciência da Política do cadastro (origem `cadastro_nao_ratificado`, sem apagar). Na primeira entrada, o gate dos Termos pede Termos e Política e grava os dois como `reaceite`.

## Para o cliente
- Ao criar a conta, você não escolhe mais a senha no formulário: ela é criada no link que chega ao seu e-mail, onde você também confirma o aceite dos Termos de uso e da Política de privacidade.
