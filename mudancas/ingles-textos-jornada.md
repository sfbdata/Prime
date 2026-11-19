---
tipo: alteracao
---
## Changelog
- Inglês: textos fixos da jornada, horas pagas, home office, justificativas e ajuste de batida na ficha do colaborador (bloco `escritorio`), das janelas de justificativa do ponto (`ponto_pendencias`) e das telas de configuração do ponto (bloco novo `ponto_config`, com os 2 JS) passam ao catálogo, com a versão em inglês. O Cálculo entra na guarda de texto fixo (já estava extraído). Só texto: nenhum cálculo, horário ou campo enviado ao servidor mudou. O nome padrão "Nova sede", que vai para o banco, continua em português. O demonstrativo do Cálculo em PDF passa a sair sempre em português (`runWithLocale('pt_BR')` no `GerarPdfDoCalculoUseCase`), porque vai para processo no Brasil. O português não muda.

## Para o cliente
- Quem usa o sistema em inglês passa a ver em inglês a jornada e as justificativas na ficha do colaborador, as janelas de justificativa do ponto e as telas de configuração do ponto. O PDF do Cálculo sai sempre em português, mesmo para quem usa o sistema em inglês, porque é peça que vai para o processo. Em português tudo continua igual.
