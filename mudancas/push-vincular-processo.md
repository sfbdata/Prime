# Push: modal "Vincular processo"

Botão "Cadastrar processo" das abas Publicações e Processos da OAB agora abre o modal do desenho 11, com dois modos:

- **Vincular existente**: busca por número/pasta entre os processos já cadastrados e inclui no monitoramento.
- **Cadastrar novo (CNJ)**: número com máscara e validação, "Buscar no CNJ" (DataJud, mesmo endpoint de Processos) preenche dados, partes e movimentações; abas Informações, Partes e Movimentações; cadastra e inclui no Push.

Sem migration. Desvio a confirmar: o desenho tem instância "Juizado Especial", que o sistema não possui como código.
