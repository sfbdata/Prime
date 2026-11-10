---
tipo: correcao
---
## Changelog
- Voto, pautas da assembleia em rascunho: o campo "Nº" das opções aceita só algarismo (o navegador tira letras e símbolos ao digitar ou colar, como os campos numéricos do desenho 18); o servidor continua recusando com "Número X deve ter 1 dígito(s).", agora coberto por teste.
- Voto, pautas da assembleia em rascunho: o "Salvar" de uma pauta grava por fetch, sem recarregar a página, e mostra o toast do desenho; o da pauta nova recarrega só depois de gravada. Antes de uma ação que recarrega ou sai da tela (atalho, remover, "Salvar e sair", "Voltar", "Continuar", abas), as pautas alteradas são gravadas; se uma for recusada, o erro aparece acima do rodapé (o erro da etapa do desenho), o que foi digitado continua na tela e repetir a ação segue sem ela; falha de rede ou de sessão só avisa e repetir tenta gravar de novo. Saída por fora da tela com alteração pendente pede confirmação do navegador.
- Incluir, salvar e remover pauta respondem JSON (`ok`, `mensagem`) ao pedido por fetch (200; 422 na recusa de validação, que a tela deixa seguir sem a pauta ao repetir; 403 na sessão expirada, que a tela tenta de novo) e seguem com o redirecionamento no envio comum.

## Para o cliente
- No Voto, o número de cada opção da pauta agora aceita só algarismos.
- No Voto, salvar uma pauta não apaga mais o que você está escrevendo nas outras pautas, e um erro ao salvar não apaga o que foi digitado. Ao trocar de etapa ou incluir outra pauta, as alterações são salvas antes.
