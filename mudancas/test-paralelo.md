---
tipo: interno
---
## Changelog
- Testes: `scripts/testar-paralelo.sh` roda a suíte com o ParaTest. Cada processo tem banco (clonado do
  banco da frente), uploads, logs e temporários próprios; a execução serial e o CI não mudam.
- Testes: o bootstrap garante que os diretórios de upload de teste existam (também na execução serial):
  havia testes que gravavam direto neles e falhavam num diretório novo ou num checkout sem `var/`.
- Testes: a criação das roles de leitura do MCP tolera dois processos criando a mesma role ao mesmo
  tempo (a corrida já existia entre frentes).
