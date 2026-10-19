# Meu Perfil e Expediente, VS 18.10.26

Cópia de leitura do projeto do Claude Design (4f6a5f61-fa47-40a0-9630-6744f1bb1cac), baixada em 19/10/2026
com DesignSync (só `list_files` e `get_file`). É a referência dos 6 PRs do redesenho do Meu Perfil:

1. menu do usuário, modal Conta do escritório (Abrir e Trocar conta) e abas do Meu Perfil
2. página Meu Perfil (selos, completude, vínculo, telefone, preferências, revisão mensal)
3. várias inscrições na OAB
4. presença on-line, horas do dia, ausência por inatividade e atalho de ponto (risco ALTO)
5. equipe da conta: situação aprovado, em análise e suspenso, menu de botão direito (risco ALTO)
6. excluir conta em 2 etapas com código por e-mail (risco ALTO)

`bluejus-central.js` não está aqui: o `get_file` corta em 256 KiB e a cópia saiu incompleta. A versão
completa anterior (16/10) está em `docs/design/trabalho-externo/bluejus-central.js`.
