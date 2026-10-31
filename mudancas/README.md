# mudancas/ — o que cada PR muda

Todo PR traz aqui **um arquivo por assunto**, com nome único (o da branch serve:
`mudancas/push-processual-unificado.md`). Arquivos diferentes não conflitam entre sessões
paralelas, e o `CHANGELOG.md` só é escrito pelo robô, no PR de release.

```markdown
---
tipo: novidade
---
## Changelog
- Texto técnico, como vai para o CHANGELOG.md.

## Para o cliente
- Texto curto, em linguagem de escritório, para a página Novidades.
```

| tipo | seção do CHANGELOG | efeito na versão |
|---|---|---|
| `remocao` | Removido | MAJOR (X.0.0): algo que o usuário usava deixa de existir |
| `novidade` | Adicionado | MINOR (x.Y.0) |
| `alteracao` | Alterado | MINOR (x.Y.0) |
| `correcao` | Corrigido | PATCH (x.y.Z) |
| `seguranca` | Segurança | PATCH (x.y.Z) |
| `interno` | (não aparece) | nenhum: CI, testes, documentação |

**"## Para o cliente" é obrigatório** nos tipos `novidade`, `alteracao` e `correcao` (o check do registro falha sem ele).
Descreva o que mudou em linguagem simples, mesmo que a tela seja só do Master (ex.: "No Painel do Master, a lista ...").
Frases como "Sem efeito para o cliente", "Nada muda..." ou "Nenhuma mudança visível" são recusadas. O tipo `interno`
continua fora da página e não precisa da seção.

Regras: cada item começa com `- `; sem travessão (— ou –); `interno` pode deixar o Changelog vazio.
Conferir localmente: `python3 scripts/mudancas.py validar` e `python3 scripts/mudancas.py resumo`.

O que acontece depois: a cada merge no master, o robô recalcula a próxima versão e atualiza o PR
**"Lança a vX.Y.Z"**. Mergear esse PR é publicar: tag, release e deploy saem sozinhos.
Política completa: [docs/versionamento.md](../docs/versionamento.md).
