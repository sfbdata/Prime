---
tipo: correcao
---
## Changelog
- Sedes (Ponto): salvar uma sede (nova ou editar) com a latitude ou a longitude vazia dava erro 500 (`Sede::setLongitude(null)`). Agora a tela volta com o aviso no campo ("Latitude é obrigatória.", "Longitude é obrigatória.") e nada é gravado; no editar a sede fica com a cerca de antes. Os avisos de faixa e de texto não numérico (latitude, longitude e raio) também passam a aparecer na tela: antes o form recusava sem dizer o motivo. O ponto eletrônico segue conferindo a batida contra a cerca gravada, sem mudança.

## Para o cliente
- Ao salvar uma sede sem localização ou com um raio fora da faixa, o BlueJus agora mostra o aviso no campo em vez de uma página de erro.
