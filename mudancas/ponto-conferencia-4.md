---
tipo: novidade
---
## Changelog
- Ponto Eletrônico, conferência ✓/✗ do desenho 16 (10/11/2026), grupo A: no catálogo do Master, "Ponto: comprovante" passa para antes de "Ponto: geolocalização" nas Funções, como o desenho.
- O PDF e o XLSX da folha saem com o nome da pessoa sem acento (`NomeDoArquivoDaFolha`): no PDF só letras em maiúsculas ("JOAODASILVA"), no XLSX letras e números.
- As janelas do esquecimento e da justificativa levam a aba aberta (campo `aba`, acompanhado pelo `ponto-abas.js`): depois de enviar, a tela volta para a mesma aba.
- O subtítulo da justificativa (`.pe-just-sub`) entra sempre na linha, vazio quando não há, como o desenho.
- A aba Auditoria busca a trilha de novo a cada vez que abre e, quando está aberta, depois de cada ação gravada na tela: batida, fila, justificativa excluída e "Sincronização" (`data-recarregar-ao-abrir`, `PontoPaineis.recarregarAbertos`). Um pedido de busca no meio de outra fica guardado e roda depois dela.
- A troca de escala na aba Equipe grava o rótulo da escala no detalhe da trilha (`escala`), e a Auditoria mostra "Nome · 44h · seg a sáb (7h20)". A versão salva pela ficha continua com o texto da versão.
- A barra do topo mostra "Ponto Eletrônico" nas telas do Ponto (rotas `ponto_` no `base.html.twig`).
- Spec `docs/specs/ponto-desenho-0811.md`: estado da conferência registrado na seção 9.

## Para o cliente
- O arquivo da folha de ponto baixado em PDF ou planilha passa a ter o nome da pessoa sem acentos, o que evita nome estranho em alguns computadores.
- Depois de enviar uma justificativa ou um esquecimento de registro, a tela do Ponto volta para a aba em que a pessoa estava.
- A aba Auditoria mostra na hora o que acabou de acontecer, como uma batida nova, sem precisar recarregar a página.
- Quando o gestor troca a escala de alguém na aba Equipe, a Auditoria mostra qual escala entrou, por exemplo "44h, seg a sáb (7h20)".
- O nome "Ponto Eletrônico" aparece na barra do topo, como nas outras telas.
- No painel do Master, a função do comprovante do Ponto aparece antes da geolocalização.
