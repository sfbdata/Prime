---
tipo: interno
---
## Changelog
- `ExpedienteQueriesDaPaginaTest`: teto de consultas do /expediente baixado para o que a página faz depois do #679 (contagens EXPEDIENTES numa consulta). Primário de 35 para 30, colaborador de 31 para 26. Assim, se uma consulta voltar a repetir, o teste quebra.

## Para o cliente
- Nada muda na tela. Foi apertada a conferência automática que garante que a tela Expedientes continue rápida: se alguma mudança futura voltar a deixar a página mais lenta, o sistema avisa antes de chegar ao cliente.
