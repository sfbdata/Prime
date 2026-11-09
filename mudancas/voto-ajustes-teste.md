---
tipo: correcao
---
## Changelog
- Voto, pautas da assembleia em rascunho: o campo "Nº" das opções aceita só algarismo (o navegador tira letras e símbolos ao digitar ou colar, como os campos numéricos do desenho 18); o servidor continua recusando com "Número X deve ter 1 dígito(s).", agora coberto por teste.
- Voto, pautas da assembleia em rascunho: o "Salvar" de uma pauta grava por fetch, sem recarregar a página, e mostra o toast do desenho. Antes de uma ação que recarrega ou sai da tela (atalho, pauta nova, remover, "Salvar e sair", "Voltar", "Continuar", abas), as pautas alteradas são gravadas; se uma falhar, o erro aparece e tudo o que foi digitado continua na tela. Incluir, salvar e remover pauta respondem JSON (`ok`, `mensagem`) ao pedido por fetch e seguem com o redirecionamento no envio comum.

## Para o cliente
- No Voto, o número de cada opção da pauta agora aceita só algarismos.
- No Voto, salvar uma pauta não recarrega mais a página: o que você está escrevendo nas outras pautas continua lá. Ao trocar de etapa ou incluir outra pauta, as alterações são salvas antes.
