---
tipo: alteracao
---
## Changelog
- Minhas Metas e a página da meta ficam iguais aos desenhos 04 e 04B de 22/10, com os componentes do desenho no lugar dos do Bootstrap/AdminLTE (spec `docs/specs/minhas-metas-esteira-2210.md`, "Estado da frente"):
  - **Página da meta:** moldura centralizada com a lateral em coluna própria, título, trilha, faixa do DESPACHO no mesmo cartão, traço da linha do tempo, Acompanhar e as janelas de responsáveis, prazo e certidão no estilo do desenho.
  - **Minhas Metas:** KPIs refeitos (sem o ícone do canto, decisão do dono), cartões separados sobre fundo cinza, pílulas, barra de filtros, trilho, janela "Configurar caixa" e aviso de sucesso embaixo, que some em 3s. "Limpar filtros" também limpa a etapa da esteira.
  - **Saem, por decisão do dono (o que o desenho não tem):** traço vermelho do título, migalha, modo lista, menu ⋮ do cartão, selo "Nº", número da meta na pasta (vira a posição no grupo), contador de comentários, bloco "Pessoas" e o lápis de editar mensagem antiga na página da meta ("(editado)" continua). "Editar nome" e "Encaminhar via Chat I.A" seguem no ⋮ da aba Metas da pasta.
  - Avisos de erro continuam na tela até a pessoa fechar.
- Sem migration.

## Para o cliente
- As telas de metas ficaram mais limpas e iguais ao novo desenho. Algumas opções pouco usadas saíram de Minhas Metas e continuam na aba Metas da pasta.
