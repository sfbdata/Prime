---
tipo: correcao
---
## Changelog
- Auditoria 2.02: comentário interno do Service Desk sai no servidor para quem não tem `admin.servicedesk.manage` (`Chamado::interacoesVisiveis`, variável `interacoes` no `show`, contador incluído).
- Auditoria 2.03: na edição legada `/tenant/{id}/edit`, o formulário de dados do escritório (nome, CNPJ, endereço) só existe para quem passa `GuardaDaEdicaoDoEscritorio` (primário ou Master). POST dele sem poder: 403 com registro de acesso negado, como no Meu Escritório. Para o secundário, a aba "Dados" fica somente leitura (campos desabilitados, sem o botão de salvar); cargos e lotações seguem iguais.
- Auditoria 3.01: o desfazer da auditoria exige prova de posse da entidade (TenantAware, Cargo, Sede, Lotação e, pelo chamado, ChamadoAnexo e ChamadoInteracao); sem prova, "Registro não encontrado.". O `AuditLogSubscriber` grava o escritório dono da entidade (não o da sessão) quando a requisição tem escritório; limitação conhecida: comando e worker seguem gravando sem escritório (gravar o dono ali levaria logs de rotina para a trilha de cada escritório).
- Auditoria 1.04: a regra C8 da troca de senha (`HierarquiaDePerfis::recusaDaSenha`) passa a usar `AcessoDaContaForaDoEscritorio`: vínculo em outro escritório, convite de escritório pendente de outro escritório para o e-mail da conta, ou convite aceito e vigente do Trabalho Externo de outro escritório. `docs/AUTORIZACAO.md` atualizado.

## Para o cliente
- Service Desk: o comentário marcado como interno pela equipe de TI não aparece mais para quem abriu o chamado.
- Dados do escritório (nome, CNPJ, endereço) só podem ser alterados pelo usuário primário, também na tela antiga de edição. Os demais veem esses dados, sem poder alterar, e seguem cuidando de cargos e lotações.
- Histórico de alterações: o botão de desfazer não alcança mais cadastro de outro escritório.
- Troca de senha de um colaborador pelo gestor fica bloqueada também quando a pessoa tem convite de outro escritório ou acesso a uma demanda de outro escritório no Trabalho Externo.
