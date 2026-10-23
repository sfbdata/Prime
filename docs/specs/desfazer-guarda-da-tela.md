# Desfazer da auditoria: a mesma guarda da tela para metas e pastas

Risco MÉDIO. Pedido do coordenador (23/10/2026). Frente da 9b, `desfazer-guarda-da-tela`. Continua o padrão do #314
(F19 da 0d: `DesfazerAlteracaoAuditLogUseCase::permissaoDeEdicao`, `exigirPermissaoDeEdicao` no
`AuditLogController` e `podeDesfazer` no botão). Sem migration.

## Estado da frente

- 23/10: spec; implementação começando.
- 23/10: implementado, commit local na branch, sem push. Ponto único: `App\Auditoria\Service\GuardaDaTelaNoDesfazer`
  (`permite` no botão, `permitePorId` antes do `executar`), que chama o mapa do F19 e as guardas da tela. Extraído do
  `TarefaController::assertAccess` o `App\Tarefa\Service\AcessoAoModuloTarefas` (a tela passa a usá-lo, mesmo
  comportamento). `TarefaMensagem` e `Notificacao` saíram das reversíveis. Testes: `DesfazerExigeAGuardaDaTelaTest`
  (16), dois casos unit no `DesfazerAlteracaoAuditLogUseCaseTest`; ajustados dois testes antigos cuja premissa mudou
  (a pasta agora pede `resources.pasta.edit`; a meta pede alguém da pasta com vínculo).
- 23/10: o coordenador decidiu que as três regras da tela que tinham ficado de fora entram (regras 2 e 3): a etapa da
  meta, a observação dos Detalhes e ver a pasta na mensagem. Testes: `DesfazerExigeAGuardaDaTelaTest` (22).
- 23/10: revisão: a meta passou a exigir por campo o que a rota pede (regra 2), com a barreira do cadeado para qualquer
  campo; saiu o `permissaoDeEdicaoPorId`, sem uso. Testes: `DesfazerExigeAGuardaDaTelaTest` (31).

## O problema

O desfazer reverte uma alteração gravando os valores antigos direto na entidade. Para metas e pastas ele exige hoje
só a permissão de ver a auditoria e o cadeado. A tela exige mais (módulo, acesso ao item, regras por campo). O
desfazer deve exigir exatamente o que a tela exigiria para fazer a mesma alteração.

## Regras

1. **Mesma guarda, chamada, não copiada.** O desfazer chama o mesmo serviço ou método que a tela usa, para herdar
   qualquer mudança futura da regra (a 3b está mudando as guardas de edição: `edicao-sem-prazo`).
2. **Tarefa (meta):** o que a rota de cada campo pede. Para qualquer campo: módulo `tarefas` (`AcessoAoModuloTarefas`),
   `AcessoAMeta::exigir` com o ator e a barreira do cadeado para escrita na pasta da meta
   (`BarreiraDoCadeadoListener::recusaria`, que a barreira usa; quem fica em Visualizar não escreve). Por campo:
   - `prazo` (`tarefa_atualizar_prazo`): também `AtualizarPrazoTarefaUseCase::podeEditarPrazo`;
   - `status` e `dataConclusao` (`tarefa_concluir` e `tarefa_reabrir`): também `QuemCuidaDaMeta::ehResponsavelOuCriador`
     e editar a pasta da meta (`AcessoAPasta::podeEditar`, o `canAccessResource('pasta', edit)` do `tarefa_reabrir`);
   - `etapa` e `etapasSaltadas` (`tarefa_passar`): também `QuemCuidaDaMeta::ehResponsavelOuCriador`;
   - `titulo` (`tarefa_renomear`): também editar a pasta da meta;
   - os demais: só a regra de qualquer campo.
   Das rotas que alteram a meta, pedem editar a pasta só `tarefa_renomear`, `tarefa_reabrir` e `tarefa_alertar` (que não
   muda campo da meta). `tarefa_concluir`, `tarefa_atualizar_prazo`, `tarefa_passar`, `tarefa_devolver`,
   `tarefa_mensagem` e `tarefa_update_responsaveis` não pedem.
3. **Pasta e filhas** (`Pasta`, `PastaDocumento`, `PastaSecao`, `PastaChecklistItem`, `PastaObservacaoDetalhes`):
   `AcessoAPasta::podeEditar(ator, pasta-mãe)` (o mesmo teto do cadeado que `canAccessResource` usa).
   `PastaObservacaoDetalhes`: também a regra da tela de edição (`EditarObservacaoDetalhesUseCase::podeEditar`: autor,
   dentro do prazo). `PastaMensagem`: o que a rota de edição pede, ver a pasta (`AcessoAPasta::podeVer`, o mesmo
   `canAccessResource` de `view`), a barreira do cadeado para escrita (nível `Editar`) e a regra da tela
   (`EditarMensagemPastaUseCase::podeEditar`: autor, dentro do prazo).
4. **Saem da lista de reversíveis** (decisão do coordenador, 23/10): `TarefaMensagem` (nenhuma tela edita ou apaga)
   e `Notificacao` (só o dono mexe na tela; desfazer a de outra pessoa não tem uso).
5. **Onde checar:** no mesmo ponto do F19 (antes do `executar` e no `podeDesfazer` do botão). Negado: 403 com a
   mensagem do F19 ("Você não tem permissão para desfazer alterações."); item de outro escritório ou pasta oculta
   continua "não encontrado". As entidades do mapa `PERMISSAO_DE_EDICAO` do F19 não mudam.
6. Textos sem travessão.

## Testes

Functional por entidade (no molde de `DesfazerExigePermissaoDeEdicaoTest`): quem tem a guarda da tela desfaz; quem
só tem a permissão da auditoria recebe 403 e o botão não aparece. Casos: meta sem acesso (AcessoAMeta), prazo por
quem não é o criador, conclusão e etapa por quem não cuida da meta, pasta sem poder editar (inclusive pelo cadeado),
filha de pasta, observação dos Detalhes de outro autor, mensagem da pasta de outro autor, do autor que não vê a pasta
e do autor que o cadeado deixa só ver, e `TarefaMensagem` e `Notificacao` não reversíveis.
