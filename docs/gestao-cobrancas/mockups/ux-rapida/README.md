# Evidências visuais — reorganização rápida da página de cobrança

Capturas do smoke real da entrega descrita em
[`../../cobranca-ux-rapida-1-dia.md`](../../cobranca-ux-rapida-1-dia.md), tiradas no preview isolado da
frente (`feature/cobranca-ux-rapida`), sobre dado de desenvolvimento.

**Isto é documentação, não asset da aplicação.** Nenhum template, CSS ou JS referencia estes arquivos —
nada aqui é servido ao usuário. Servem para conferir o que foi entregue sem precisar subir o ambiente.

| Arquivo | O que mostra |
|---|---|
| `01-claro-cobranca.png` | Aba Cobrança, tema claro: cabeçalho com total em aberto/vencido, barra de atividades com as 9 opções, editor de anotação e a anotação salva em "Anotações recentes" |
| `02-escuro-cobranca.png` | A mesma aba no tema escuro |
| `03-claro-divida.png` | Aba Dívida: composição completa (original, juros, multa, correção, honorários, total) e `Editar configuração de encargos` no contexto financeiro |
| `04-escuro-honorarios.png` | Aba Honorários: forma, percentual, base e carência configurados + valores por obrigação |
| `05-claro-excluir-confirmacao.png` | Confirmação de exclusão mostrando um trecho da anotação |
| `06-escuro-estreito-mais-acoes.png` | 820px de largura: as 4 opções que a SPEC §6.4 manda manter visíveis + `Mais ações` |
| `07-escuro-registrar-contato.png` | Modal `Registrar contato` — relato em textarea simples (o editor rico ficou fora desta entrega) |

A barra de formatação nas imagens tem negrito, itálico, sublinhado, tachado, cor, listas, recuo,
alinhamento, citação e limpar formatação. **Link não faz parte dela**: o sanitizador `textoRico` não
aceita `<a>`, e liberá-lo mudaria o comportamento de Pasta e Tarefa — fora do escopo desta frente.
