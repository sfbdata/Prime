# Evidências visuais — reorganização rápida da página de cobrança

Capturas do smoke real da entrega descrita em
[`../../cobranca-ux-rapida-1-dia.md`](../../cobranca-ux-rapida-1-dia.md), tiradas no preview isolado da
frente (`feature/cobranca-ux-rapida`), sobre dado de desenvolvimento.

**Isto é documentação, não asset da aplicação.** Nenhum template, CSS ou JS referencia estes arquivos —
nada aqui é servido ao usuário. Servem para conferir o que foi entregue sem precisar subir o ambiente.

| Arquivo | O que mostra |
|---|---|
| `01-claro-cobranca.png` | Aba Cobrança, tema claro: cabeçalho, barra de 7 opções, **próxima ação compacta** no topo e o editor de anotação |
| `02-escuro-cobranca.png` | Aba Responsáveis no tema escuro: atual em destaque, demais em accordion |
| `03-claro-divida.png` | Aba Dívida em **largura total**: composição completa (original, juros, multa, correção, honorários, total) e `Editar configuração de encargos` |
| `04-escuro-honorarios.png` | Aba Honorários: forma, percentual, base e carência configurados + valores por obrigação |
| `05-claro-excluir-confirmacao.png` | Confirmação de exclusão mostrando um trecho da anotação |
| `06-escuro-estreito-mais-acoes.png` | 820px de largura: as 4 opções que a SPEC §6.4 manda manter visíveis + `Mais ações` |
| `07-escuro-registrar-contato.png` | Modal `Registrar contato` — relato em textarea simples (o editor rico ficou fora desta entrega) |
| `08-claro-responsaveis.png` | Aba **Responsáveis**: responsável atual expandido no topo, demais em accordion com `Definir como atual`, `Editar` e `Encerrar vínculo` |
| `09-claro-definir-como-atual.png` | `Definir como atual` em um clique: abre o modal de troca **já com a pessoa selecionada**, faltando só o motivo (obrigatório por regra do domínio) |
| `10-escuro-homonimo-definir-como-atual.png` | Depois do conserto dos homônimos: `CRUZEIRO E SOUSA IMOVEIS LTDA ME` (um de **seis** com o mesmo nome) tem `Definir como atual` **habilitado**. Antes o botão vinha desabilitado, porque o modal não conseguia selecionar essa pessoa |
| `11-escuro-anotacoes-rolagem.png` | Lista de anotações com **todas** as 14 no HTML e rolagem dentro do bloco (352px de 1112px de conteúdo); o contador no cabeçalho substituiu o aviso "mostrando as 10 mais recentes". Anotações semeadas só para a captura e apagadas depois |
| `12-escuro-encargos-valores-da-carteira.png` | `Editar configuração de encargos` mostrando **os valores da carteira TOP LIFE II** em cinza (1,00% de juros, 2,00% de multa, 15,00% de honorários, 30 dias de carência) no lugar do selo "Herda da carteira". Todos os campos estão **vazios** — o cinza é placeholder, não valor gravado |

A barra de formatação nas imagens tem negrito, itálico, sublinhado, tachado, cor, listas, recuo,
alinhamento, citação e limpar formatação. **Link não faz parte dela**: o sanitizador `textoRico` não
aceita `<a>`, e liberá-lo mudaria o comportamento de Pasta e Tarefa — fora do escopo desta frente.
