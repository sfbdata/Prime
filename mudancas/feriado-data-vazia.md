---
tipo: correcao
---
## Changelog
- Ponto: salvar um feriado (novo ou editar) com a data vazia dava erro 500 (`Feriado::setData(null)`). Agora a tela volta com "Informe a data do feriado." no campo Data (código 422) e nada é gravado; no editar o feriado fica como estava. O mesmo vale para o nome vazio no editar ("O nome é obrigatório.").

## Para o cliente
- Ao salvar um feriado sem preencher a data ou o nome, o BlueJus agora mostra o aviso no campo em vez de uma página de erro.
