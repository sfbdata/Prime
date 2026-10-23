# Desfazer da auditoria: a mesma guarda da tela para metas e pastas

Risco MÉDIO. Pedido do coordenador (23/10/2026). Frente da 9b, `desfazer-guarda-da-tela`. Continua o padrão do #314
(F19 da 0d: `DesfazerAlteracaoAuditLogUseCase::permissaoDeEdicao`, `exigirPermissaoDeEdicao` no
`AuditLogController` e `podeDesfazer` no botão). Sem migration.

## Estado da frente

- 23/10: spec; implementação começando.

## O problema

O desfazer reverte uma alteração gravando os valores antigos direto na entidade. Para metas e pastas ele exige hoje
só a permissão de ver a auditoria e o cadeado. A tela exige mais (módulo, acesso ao item, regras por campo). O
desfazer deve exigir exatamente o que a tela exigiria para fazer a mesma alteração.

## Regras

1. **Mesma guarda, chamada, não copiada.** O desfazer chama o mesmo serviço ou método que a tela usa, para herdar
   qualquer mudança futura da regra (a 3b está mudando as guardas de edição: `edicao-sem-prazo`).
2. **Tarefa (meta):** módulo `tarefas` (`canAccessModule`) e `AcessoAMeta::exigir` com o ator. Se o diff tem o
   campo do prazo, também a regra do prazo da tela (`AtualizarPrazoTarefaUseCase::podeEditarPrazo`). Se tem a
   conclusão ou a reabertura, a regra de quem pode concluir (`QuemCuidaDaMeta::ehResponsavelOuCriador`). Os demais
   campos: módulo e acesso à meta.
3. **Pasta e filhas** (`Pasta`, `PastaDocumento`, `PastaSecao`, `PastaChecklistItem`, `PastaObservacaoDetalhes`):
   `AcessoAPasta::podeEditar(ator, pasta-mãe)` (o mesmo teto do cadeado que `canAccessResource` usa).
   `PastaMensagem`: a regra da tela (`EditarMensagemPastaUseCase::podeEditar`: autor, dentro do prazo).
4. **Saem da lista de reversíveis** (decisão do coordenador, 23/10): `TarefaMensagem` (nenhuma tela edita ou apaga)
   e `Notificacao` (só o dono mexe na tela; desfazer a de outra pessoa não tem uso).
5. **Onde checar:** no mesmo ponto do F19 (antes do `executar` e no `podeDesfazer` do botão). Negado: 403 com a
   mensagem do F19 ("Você não tem permissão para desfazer alterações."); item de outro escritório ou pasta oculta
   continua "não encontrado". As entidades do mapa `PERMISSAO_DE_EDICAO` do F19 não mudam.
6. Textos sem travessão.

## Testes

Functional por entidade (no molde de `DesfazerExigePermissaoDeEdicaoTest`): quem tem a guarda da tela desfaz; quem
só tem a permissão da auditoria recebe 403 e o botão não aparece. Casos: meta sem acesso (AcessoAMeta), prazo por
quem não é o criador, conclusão por quem não cuida da meta, pasta sem poder editar (inclusive pelo cadeado), filha
de pasta, mensagem da pasta de outro autor, e `TarefaMensagem` e `Notificacao` não reversíveis.
