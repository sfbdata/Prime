# Regressão visual (Playwright)

Os testes PHPUnit leem o HTML, não a tela: já passaram milhares de testes com o layout visivelmente
quebrado. A regressão visual fotografa as telas num navegador de verdade e compara com uma foto de
**referência aprovada pelo dono**. Se algo mudou na aparência sem querer (botão fora do lugar, cor,
fonte, coluna caída), o teste fica vermelho e mostra as três imagens: antes, depois e a diferença.

## O que é fotografado

`e2e/tests/visual/telas.spec.js`, em desktop (1440×900) e celular (Pixel 7):

| Tela | O que a máscara cobre (não é comparado) |
|---|---|
| Login | nada |
| Política de Privacidade (início) | nada |
| Cabeçalho e menu lateral | contadores (sino, Central) |
| Dashboard | o conteúdo de cada cartão e tabela: só o arranjo é comparado |
| Expediente | as linhas da tabela e os contadores |
| Novidades | a linha com a versão atual |

A máscara existe porque os dados do banco de DEV mudam; o que se compara é o desenho. A data do
navegador é fixa (20/10/2026) pelo mesmo motivo.

## Como usar (o dono roda: smoke no navegador é dele)

```bash
cd e2e
npm run test:visual:aprovar     # 1ª vez: grava as fotos de referência
# olhe as fotos em e2e/tests/visual/__referencias__/ ; se estiverem certas, commite-as
npm run test:visual             # depois de qualquer mudança: compara com as referências
npm run test:visual:relatorio   # se falhou: abre as imagens antes/depois/diferença
```

- **Diferença esperada** (a tela mudou de propósito): `npm run test:visual:aprovar` e commite as
  fotos novas no mesmo PR da mudança: quem revisa o PR vê a imagem nova.
- **Diferença inesperada:** é defeito. Corrija antes do merge.
- Precisa do ambiente de DEV no ar (`localhost:8080`) e do usuário de E2E (`e2e/.env`).

## Limites

- Não roda no CI do GitHub: as telas logadas precisam do banco de DEV com dados.
- Pega mudança de aparência; não diz se um cálculo está certo (isso continua com o PHPUnit).
- Tela nova não tem referência: o primeiro `aprovar` dela é a sua aprovação.
- Diferença de fonte entre máquinas: grave e compare sempre na mesma máquina.
