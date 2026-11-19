---
tipo: novidade
---
## Changelog
- Bandeira do idioma no topo e "Opções avançadas" no Meu Perfil (spec `docs/specs/sistema-em-ingles.md`, pedido do Farlei de 19/11). A bandeira (SVG, antes do botão de tela cheia) abre o modal "Idioma do sistema" e some com o recurso `idioma`. A linha nova "Opções avançadas" abre um modal com idioma, fuso horário (12 opções), formato de data (automático, 31/12/2026, 12/31/2026, 2026-12-31) e de números (automático, 1.234,56, 1,234.56), gravados de uma vez por `POST /perfil/opcoes-avancadas` (`SalvarOpcoesAvancadasUseCase`). Migration `Version20261119110000`: `user_profiles.fuso_horario`, `formato_data` e `formato_numero`, nulas (padrão). O `FormatoDaRequisicaoListener` leva as escolhas à requisição (atributos `_bj_*`, guardados na sessão) e ao `<html data-bj-*>`; os filtros `|data`, `|data_hora`, `|hora`, `|numero` e o `BJFormato` passam a respeitá-las. O fuso vale só para registro de data e hora, nunca para data pura (prazo e audiência). A moeda continua real. Catálogo novo `opcoes_avancadas` em português e inglês. O inglês continua "em breve" no seletor até os catálogos em inglês ficarem prontos.

## Para o cliente
- No canto de cima da tela aparece a bandeira do idioma em uso. Clicando nela, você abre a escolha do idioma do sistema. O inglês chega em breve.
- No Meu Perfil, em Preferências, há a nova linha "Opções avançadas". Nela você escolhe o fuso horário, o jeito de escrever a data (por exemplo 31/12/2026 ou 12/31/2026) e o jeito de escrever números e valores (1.234,56 ou 1,234.56). Vale só para você.
- Os valores continuam em reais. Prazos e audiências continuam no horário de Brasília, que é o da Justiça; o fuso escolhido muda só a hora dos registros, como "quando foi feito".
