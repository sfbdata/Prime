---
tipo: novidade
---
## Changelog
- Cálculo: o código de verificação impresso no rodapé do PDF agora vale de verdade. Cada PDF emitido fica registrado (tabela `calculo_emitido`, migration `Version20261030120000`) e a página pública `/calculo/verificar`, sem login, confirma o código mostrando só o que o PDF já mostra (escritório, data de emissão, data final e total). Quem recebe o PDF pode também enviá-lo na página para saber se ele é idêntico ao original (o arquivo não é guardado).
