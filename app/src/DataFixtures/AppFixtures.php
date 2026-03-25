<?php

namespace App\DataFixtures;

use App\Entity\Tenant\Tenant;
use App\Entity\Agenda\Evento;
use App\Entity\Auth\User;
use App\Entity\Comercial\PreCadastro;
use App\Entity\Cliente\ClientePF;
use App\Entity\Cliente\ClientePJ;
use App\Entity\Processo\Processo;
use App\Entity\Processo\ParteProcesso;
use App\Entity\Processo\MovimentacaoProcesso;
use App\Entity\Processo\DocumentoProcesso;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\AtribuicaoTarefa;
use App\Entity\Tarefa\TarefaMensagem;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {}

    public function load(ObjectManager $manager): void
    {
        // =============================================
        // 1. USUÁRIOS (e Tenant)
        // =============================================
        $users = $this->loadUsers($manager);

        // =============================================
        // 2. CLIENTES PF
        // =============================================
        $clientesPF = $this->loadClientesPF($manager);

        // =============================================
        // 3. CLIENTES PJ
        // =============================================
        $clientesPJ = $this->loadClientesPJ($manager);

        // =============================================
        // 4. PRÉ-CADASTROS
        // =============================================
        $this->loadPreCadastros($manager, $clientesPF, $clientesPJ);

        // =============================================
        // 5. PROCESSOS
        // =============================================
        $processos = $this->loadProcessos($manager);

        // =============================================
        // 7. TAREFAS
        // =============================================
        $this->loadTarefas($manager, $processos, $users);

        // =============================================
        // 8. CHAMADOS (SERVICE DESK)
        // =============================================
        $this->loadChamados($manager, $users);

        // =============================================
        // 9. EVENTOS DE AGENDA
        // FIX: datas com horário construídas corretamente via setTime().
        // =============================================
        $this->loadEventos($manager, $users);

        $manager->flush();
    }

    // -----------------------------------------------
    // USUÁRIOS
    // -----------------------------------------------
    private function loadUsers(ObjectManager $manager): array
    {
        // Cria o Tenant do escritório de demonstração
        // FIX: removido setIsActive(true) — o construtor do Tenant já define true automaticamente.
        $tenant = new Tenant();
        $tenant->setName('Escritório Almeida & Associados');
        $manager->persist($tenant);

        $dados = [
            ['email' => 'admin@escritorio.com.br',      'nome' => 'Dr. Ricardo Almeida',    'roles' => ['ROLE_ADMIN'],      'senha' => 'admin123', 'ativo' => true],
            ['email' => 'advogado1@escritorio.com.br',  'nome' => 'Dra. Fernanda Costa',    'roles' => ['ROLE_ADVOGADO'],   'senha' => 'senha123', 'ativo' => true],
            ['email' => 'advogado2@escritorio.com.br',  'nome' => 'Dr. Marcelo Souza',      'roles' => ['ROLE_ADVOGADO'],   'senha' => 'senha123', 'ativo' => true],
            ['email' => 'estagiario@escritorio.com.br', 'nome' => 'Lucas Pereira',          'roles' => ['ROLE_ESTAGIARIO'], 'senha' => 'senha123', 'ativo' => true],
            ['email' => 'secretaria@escritorio.com.br', 'nome' => 'Ana Paula Rodrigues',    'roles' => ['ROLE_SECRETARIA'], 'senha' => 'senha123', 'ativo' => true],
            ['email' => 'ti@escritorio.com.br',         'nome' => 'Carlos Eduardo Lima',    'roles' => ['ROLE_TI'],         'senha' => 'senha123', 'ativo' => true],
        ];

        $users = [];
        foreach ($dados as $dado) {
            $user = new User();
            $user->setEmail($dado['email']);
            $user->setFullName($dado['nome']);
            $user->setRoles($dado['roles']);
            $user->setIsActive($dado['ativo']);
            $user->setTenant($tenant);
            $user->setPassword($this->passwordHasher->hashPassword($user, $dado['senha']));
            $manager->persist($user);
            $users[] = $user;
        }

        return $users;
    }

    // -----------------------------------------------
    // CLIENTES PF
    // -----------------------------------------------
    private function loadClientesPF(ObjectManager $manager): array
    {
        $dados = [
            [
                'nomeCompleto'    => 'João Carlos Ferreira',
                'cpf'             => '123.456.789-01',
                'rg'              => '1234567',
                'rgOrgao'         => 'SSP/SP',
                'rgDataEmissao'   => '2010-03-15',
                'dataNascimento'  => '1985-07-20',
                'estadoCivil'     => 'Casado',
                'profissao'       => 'Engenheiro Civil',
                'email'           => 'joao.ferreira@email.com',
                'celular'         => '(11) 98765-4321',
                'cep'             => '01310100',
                'endereco'        => 'Av. Paulista, 1000, Apto 52',
                'cidade'          => 'São Paulo',
                'estado'          => 'SP',
            ],
            [
                'nomeCompleto'    => 'Maria Aparecida Silva',
                'cpf'             => '234.567.890-12',
                'rg'              => '2345678',
                'rgOrgao'         => 'SSP/MG',
                'rgDataEmissao'   => '2008-06-22',
                'dataNascimento'  => '1972-11-05',
                'estadoCivil'     => 'Divorciada',
                'profissao'       => 'Professora',
                'email'           => 'maria.silva@email.com',
                'celular'         => '(31) 97654-3210',
                'cep'             => '30130010',
                'endereco'        => 'Rua da Bahia, 500, Sala 301',
                'cidade'          => 'Belo Horizonte',
                'estado'          => 'MG',
            ],
            [
                'nomeCompleto'    => 'Roberto Nascimento Santos',
                'cpf'             => '345.678.901-23',
                'rg'              => '3456789',
                'rgOrgao'         => 'SSP/RJ',
                'rgDataEmissao'   => '2012-01-10',
                'dataNascimento'  => '1990-04-14',
                'estadoCivil'     => 'Solteiro',
                'profissao'       => 'Médico',
                'email'           => 'roberto.santos@email.com',
                'celular'         => '(21) 96543-2109',
                'cep'             => '20040020',
                'endereco'        => 'Av. Rio Branco, 200, Bloco B',
                'cidade'          => 'Rio de Janeiro',
                'estado'          => 'RJ',
            ],
            [
                'nomeCompleto'    => 'Patrícia Oliveira Mendes',
                'cpf'             => '456.789.012-34',
                'rg'              => '4567890',
                'rgOrgao'         => 'SSP/RS',
                'rgDataEmissao'   => '2015-09-30',
                'dataNascimento'  => '1980-02-28',
                'estadoCivil'     => 'Casada',
                'profissao'       => 'Contadora',
                'email'           => 'patricia.mendes@email.com',
                'celular'         => '(51) 95432-1098',
                'cep'             => '90010140',
                'endereco'        => 'Rua dos Andradas, 1200, Conj. 84',
                'cidade'          => 'Porto Alegre',
                'estado'          => 'RS',
            ],
            [
                'nomeCompleto'    => 'Antônio Carlos Gomes',
                'cpf'             => '567.890.123-45',
                'rg'              => '5678901',
                'rgOrgao'         => 'SSP/BA',
                'rgDataEmissao'   => '2009-11-17',
                'dataNascimento'  => '1965-08-12',
                'estadoCivil'     => 'Viúvo',
                'profissao'       => 'Comerciante',
                'email'           => 'antonio.gomes@email.com',
                'celular'         => '(71) 94321-0987',
                'cep'             => '40010010',
                'endereco'        => 'Av. Sete de Setembro, 300',
                'cidade'          => 'Salvador',
                'estado'          => 'BA',
            ],
        ];

        $clientes = [];
        foreach ($dados as $dado) {
            $c = new ClientePF();
            $c->setNomeCompleto($dado['nomeCompleto']);
            $c->setCpf($dado['cpf']);
            $c->setRg($dado['rg']);
            $c->setRgOrgaoExpedidor($dado['rgOrgao']);
            $c->setRgDataEmissao(new \DateTime($dado['rgDataEmissao']));
            $c->setDataNascimento(new \DateTime($dado['dataNascimento']));
            $c->setEstadoCivil($dado['estadoCivil']);
            $c->setProfissao($dado['profissao']);
            $c->setEmail($dado['email']);
            $c->setTelefoneCelular($dado['celular']);
            $c->setCep($dado['cep']);
            $c->setEndereco($dado['endereco']);
            $c->setCidade($dado['cidade']);
            $c->setEstado($dado['estado']);
            $manager->persist($c);
            $clientes[] = $c;
        }

        return $clientes;
    }

    // -----------------------------------------------
    // CLIENTES PJ
    // -----------------------------------------------
    private function loadClientesPJ(ObjectManager $manager): array
    {
        $dados = [
            [
                'razaoSocial'        => 'Construtora Horizonte Ltda.',
                'nomeFantasia'       => 'Horizonte Construções',
                'cnpj'               => '12345678000190',
                'inscricaoEstadual'  => '123456789110',
                'inscricaoMunicipal' => '12345678',
                'enderecSede'        => 'Av. das Nações Unidas, 14401, Torre A',
                'repLegal'           => 'Marcos Antônio Braga',
                'repCpf'             => '67890123456',
                'repRg'              => '6789012',
                'repCargo'           => 'Diretor Executivo',
                'email'              => 'juridico@horizonteconstrucoes.com.br',
                'celular'            => '(11) 3456-7890',
                'cep'                => '04794000',
                'endereco'           => 'Av. das Nações Unidas, 14401',
                'cidade'             => 'São Paulo',
                'estado'             => 'SP',
            ],
            [
                'razaoSocial'        => 'Supermercados Família S.A.',
                'nomeFantasia'       => 'Família Supermercados',
                'cnpj'               => '23456789000101',
                'inscricaoEstadual'  => '234567890221',
                'inscricaoMunicipal' => '23456789',
                'enderecSede'        => 'Rua Comercial, 500, Centro',
                'repLegal'           => 'Sandra Cristina Farias',
                'repCpf'             => '78901234567',
                'repRg'              => '7890123',
                'repCargo'           => 'Sócia Administradora',
                'email'              => 'contato@familiasupermercados.com.br',
                'celular'            => '(41) 3567-8901',
                'cep'                => '80010020',
                'endereco'           => 'Rua Marechal Deodoro, 630',
                'cidade'             => 'Curitiba',
                'estado'             => 'PR',
            ],
            [
                'razaoSocial'        => 'TechSoft Sistemas de Informação Ltda.',
                'nomeFantasia'       => 'TechSoft',
                'cnpj'               => '34567890000112',
                'inscricaoEstadual'  => null,
                'inscricaoMunicipal' => '34567890',
                'enderecSede'        => 'Rua do Parque Tecnológico, 200',
                'repLegal'           => 'Felipe Augusto Nunes',
                'repCpf'             => '89012345678',
                'repRg'              => '8901234',
                'repCargo'           => 'CEO',
                'email'              => 'legal@techsoft.com.br',
                'celular'            => '(48) 3678-9012',
                'cep'                => '88034001',
                'endereco'           => 'Parque Tecnológico Alfa, Bloco C',
                'cidade'             => 'Florianópolis',
                'estado'             => 'SC',
            ],
        ];

        $clientes = [];
        foreach ($dados as $dado) {
            $c = new ClientePJ();
            $c->setRazaoSocial($dado['razaoSocial']);
            $c->setNomeFantasia($dado['nomeFantasia']);
            $c->setCnpj($dado['cnpj']);
            $c->setInscricaoEstadual($dado['inscricaoEstadual']);
            $c->setInscricaoMunicipal($dado['inscricaoMunicipal']);
            $c->setEnderecSede($dado['enderecSede']);
            $c->setRepresentanteLegal($dado['repLegal']);
            $c->setRepresentanteCpf($dado['repCpf']);
            $c->setRepresentanteRg($dado['repRg']);
            $c->setRepresentanteCargo($dado['repCargo']);
            $c->setEmail($dado['email']);
            $c->setTelefoneCelular($dado['celular']);
            $c->setCep($dado['cep']);
            $c->setEndereco($dado['endereco']);
            $c->setCidade($dado['cidade']);
            $c->setEstado($dado['estado']);
            $manager->persist($c);
            $clientes[] = $c;
        }

        return $clientes;
    }

    // -----------------------------------------------
    // PRÉ-CADASTROS
    // -----------------------------------------------
    private function loadPreCadastros(ObjectManager $manager, array $pfs, array $pjs): void
    {
        $dados = [
            [
                'nomeCliente'       => 'Carlos Eduardo Martins',
                'cpf'               => '90123456789',
                'tipo'              => 'PF',
                'telefone'          => '(11) 91234-5678',
                'areaDireito'       => 'Direito Trabalhista',
                'prazo'             => '+15 days',
                'natureza'          => 'Judicial',
                'faseJudicial'      => 'Inicial',
                'numeroProcesso'    => null,
                'numeroContrato'    => null,
                'descricaoContrato' => 'Ação trabalhista por rescisão indireta. Cliente alega descumprimento de obrigações contratuais pelo empregador.',
                'valorContrato'     => '3500.00',
                'statusContrato'    => 'PENDENTE',
                'cliente'           => null,
            ],
            [
                'nomeCliente'       => 'Fernanda Queiroz Lima',
                'cpf'               => '01234567890',
                'tipo'              => 'PF',
                'telefone'          => '(31) 92345-6789',
                'areaDireito'       => 'Direito de Família',
                'prazo'             => '+30 days',
                'natureza'          => 'Judicial',
                'faseJudicial'      => 'Contestação',
                'numeroProcesso'    => '1234567-89.2023.8.26.0100',
                'numeroContrato'    => 'CTR-2024-042',
                'descricaoContrato' => 'Ação de divórcio litigioso com discussão sobre guarda compartilhada e partilha de bens.',
                'valorContrato'     => '8000.00',
                'statusContrato'    => 'ATIVO',
                'cliente'           => $pfs[1],
            ],
            [
                'nomeCliente'       => 'Horizonte Construções',
                'cpf'               => '12345678000190',
                'tipo'              => 'PJ - Empresa',
                'telefone'          => '(11) 3456-7890',
                'areaDireito'       => 'Direito Empresarial',
                'prazo'             => '+45 days',
                'natureza'          => 'Consultivo',
                'faseJudicial'      => null,
                'numeroProcesso'    => null,
                'numeroContrato'    => 'CTR-2024-018',
                'descricaoContrato' => 'Assessoria jurídica empresarial mensal, incluindo análise de contratos, compliance e consultoria tributária.',
                'valorContrato'     => '15000.00',
                'statusContrato'    => 'ATIVO',
                'cliente'           => $pjs[0],
            ],
            [
                'nomeCliente'       => 'Bruno Rafael Costa',
                'cpf'               => '11345678901',
                'tipo'              => 'PF',
                'telefone'          => '(21) 93456-7890',
                'areaDireito'       => 'Direito do Consumidor',
                'prazo'             => '+7 days',
                'natureza'          => 'Judicial',
                'faseJudicial'      => 'Recurso',
                'numeroProcesso'    => '9876543-21.2022.8.19.0001',
                'numeroContrato'    => 'CTR-2023-097',
                'descricaoContrato' => 'Recurso em ação de indenização por danos morais decorrentes de inscrição indevida em órgãos de proteção ao crédito.',
                'valorContrato'     => '2800.00',
                'statusContrato'    => 'ATIVO',
                'cliente'           => $pfs[2],
            ],
            [
                'nomeCliente'       => 'TechSoft Sistemas',
                'cpf'               => '34567890000112',
                'tipo'              => 'PJ - Empresa',
                'telefone'          => '(48) 3678-9012',
                'areaDireito'       => 'Direito Digital e Propriedade Intelectual',
                'prazo'             => null,
                'natureza'          => 'Consultivo',
                'faseJudicial'      => null,
                'numeroProcesso'    => null,
                'numeroContrato'    => null,
                'descricaoContrato' => 'Consulta sobre proteção de software e registro de marca. Análise de contrato de licenciamento de tecnologia.',
                'valorContrato'     => '5500.00',
                'statusContrato'    => null,
                'cliente'           => $pjs[2],
            ],
        ];

        foreach ($dados as $dado) {
            $pc = new PreCadastro();
            $pc->setNomeCliente($dado['nomeCliente']);
            $pc->setCpf($dado['cpf']);
            $pc->setTipo($dado['tipo']);
            $pc->setTelefone($dado['telefone']);
            $pc->setAreaDireito($dado['areaDireito']);
            if ($dado['prazo']) {
                $pc->setPrazo(new \DateTimeImmutable($dado['prazo']));
            }
            $pc->setNatureza($dado['natureza']);
            $pc->setFaseJudicial($dado['faseJudicial']);
            $pc->setNumeroProcesso($dado['numeroProcesso']);
            $pc->setNumeroContrato($dado['numeroContrato']);
            $pc->setDescricaoContrato($dado['descricaoContrato']);
            $pc->setValorContrato($dado['valorContrato']);
            $pc->setStatusContrato($dado['statusContrato']);
            $pc->setCliente($dado['cliente']);
            $manager->persist($pc);
        }
    }

    // -----------------------------------------------
    // PROCESSOS
    // -----------------------------------------------
    private function loadProcessos(ObjectManager $manager): array
    {
        $dados = [
            [
                'numero'         => '0001234-56.2024.5.02.0001',
                'orgaoJulgador'  => '1ª Vara do Trabalho de São Paulo',
                'sigla'          => 'TRT2',
                'classe'         => 'Reclamação Trabalhista',
                'assunto'        => 'Rescisão Indireta do Contrato de Trabalho',
                'situacao'       => 'Em Andamento',
                'instancia'      => '1ª Instância',
                'distribuicao'   => '2024-01-25',
                'partes'         => [
                    ['tipo' => 'RECLAMANTE', 'nome' => 'João Carlos Ferreira',       'documento' => '123.456.789-01',      'papel' => 'Autor'],
                    ['tipo' => 'RECLAMADO',  'nome' => 'Empresa XYZ Comércio Ltda.', 'documento' => '98.765.432/0001-10',  'papel' => 'Réu'],
                ],
                'movimentacoes' => [
                    ['data' => '2024-01-25', 'descricao' => 'Distribuição do processo por dependência',                              'tipo' => 'Distribuição', 'orgao' => 'TRT2'],
                    ['data' => '2024-02-10', 'descricao' => 'Notificação do reclamado para apresentar defesa no prazo de 10 dias',   'tipo' => 'Notificação',  'orgao' => 'TRT2'],
                    ['data' => '2024-03-01', 'descricao' => 'Apresentação de contestação pelo reclamado',                            'tipo' => 'Petição',      'orgao' => 'TRT2'],
                    ['data' => '2024-04-15', 'descricao' => 'Audiência de instrução e julgamento designada para 15/04/2024',         'tipo' => 'Pauta',        'orgao' => 'TRT2'],
                ],
                'docs' => [
                    ['tipo' => DocumentoProcesso::TIPO_PECA,                   'nome' => 'Peticao_Inicial_Joao_Ferreira.pdf'],
                    ['tipo' => DocumentoProcesso::TIPO_PROCURACAO,             'nome' => 'Procuracao_Joao_Ferreira.pdf'],
                    ['tipo' => DocumentoProcesso::TIPO_IDENTIFICACAO,          'nome' => 'RG_CPF_Joao_Ferreira.pdf'],
                    ['tipo' => DocumentoProcesso::TIPO_COMPROVANTE_RESIDENCIA, 'nome' => 'Comprovante_Residencia_Joao_Ferreira.pdf'],
                ],
                'docFlags' => ['peca' => true, 'procuracao' => true, 'identificacao' => true, 'residencia' => true, 'gratuidade' => false, 'demais' => false],
            ],
            [
                'numero'         => '1234567-89.2023.8.26.0100',
                'orgaoJulgador'  => '3ª Vara de Família e Sucessões de São Paulo',
                'sigla'          => 'TJSP',
                'classe'         => 'Divórcio Litigioso',
                'assunto'        => 'Divórcio com Partilha de Bens e Guarda de Filhos',
                'situacao'       => 'Em Andamento',
                'instancia'      => '1ª Instância',
                'distribuicao'   => '2023-11-10',
                'partes'         => [
                    ['tipo' => 'AUTOR', 'nome' => 'Maria Aparecida Silva',   'documento' => '234.567.890-12', 'papel' => 'Requerente'],
                    ['tipo' => 'REU',   'nome' => 'Carlos Henrique Moreira', 'documento' => '321.654.987-00', 'papel' => 'Requerido'],
                ],
                'movimentacoes' => [
                    ['data' => '2023-11-10', 'descricao' => 'Petição inicial protocolada',                       'tipo' => 'Distribuição', 'orgao' => 'TJSP'],
                    ['data' => '2023-12-05', 'descricao' => 'Citação do requerido realizada com sucesso',        'tipo' => 'Citação',      'orgao' => 'TJSP'],
                    ['data' => '2024-01-15', 'descricao' => 'Audiência de mediação — acordo não obtido',         'tipo' => 'Audiência',    'orgao' => 'TJSP'],
                    ['data' => '2024-03-20', 'descricao' => 'Juntada de contestação pelo requerido',             'tipo' => 'Petição',      'orgao' => 'TJSP'],
                ],
                'docs' => [
                    ['tipo' => DocumentoProcesso::TIPO_PECA,         'nome' => 'Peticao_Inicial_Divorcio.pdf'],
                    ['tipo' => DocumentoProcesso::TIPO_PROCURACAO,   'nome' => 'Procuracao_Maria_Silva.pdf'],
                    ['tipo' => DocumentoProcesso::TIPO_IDENTIFICACAO,'nome' => 'Documentos_Identificacao_Maria.pdf'],
                ],
                'docFlags' => ['peca' => true, 'procuracao' => true, 'identificacao' => true, 'residencia' => false, 'gratuidade' => false, 'demais' => false],
            ],
            [
                'numero'         => '9876543-21.2022.8.19.0001',
                'orgaoJulgador'  => '5ª Câmara de Direito Privado - TJRJ',
                'sigla'          => 'TJRJ',
                'classe'         => 'Apelação Cível',
                'assunto'        => 'Indenização por Danos Morais - Negativação Indevida',
                'situacao'       => 'Aguardando Julgamento',
                'instancia'      => '2ª Instância',
                'distribuicao'   => '2022-05-18',
                'partes'         => [
                    ['tipo' => 'APELANTE', 'nome' => 'Roberto Nascimento Santos', 'documento' => '345.678.901-23',      'papel' => 'Recorrente'],
                    ['tipo' => 'APELADO',  'nome' => 'Banco Meridional S.A.',     'documento' => '01.023.456/0001-54',  'papel' => 'Recorrido'],
                ],
                'movimentacoes' => [
                    ['data' => '2022-05-18', 'descricao' => 'Ajuizamento da ação na 1ª instância',                          'tipo' => 'Distribuição', 'orgao' => 'TJRJ'],
                    ['data' => '2022-10-30', 'descricao' => 'Sentença de improcedência proferida em 1ª instância',           'tipo' => 'Sentença',     'orgao' => 'TJRJ'],
                    ['data' => '2022-12-01', 'descricao' => 'Recurso de apelação interposto',                                'tipo' => 'Recurso',      'orgao' => 'TJRJ'],
                    ['data' => '2023-04-10', 'descricao' => 'Processo distribuído na 2ª instância para a 5ª Câmara',        'tipo' => 'Distribuição', 'orgao' => 'TJRJ'],
                    ['data' => '2024-01-22', 'descricao' => 'Incluído em pauta de julgamento para março/2024',               'tipo' => 'Pauta',        'orgao' => 'TJRJ'],
                ],
                'docs' => [
                    ['tipo' => DocumentoProcesso::TIPO_PECA,                   'nome' => 'Razoes_Apelacao.pdf'],
                    ['tipo' => DocumentoProcesso::TIPO_PROCURACAO,             'nome' => 'Procuracao_Roberto_Santos.pdf'],
                    ['tipo' => DocumentoProcesso::TIPO_IDENTIFICACAO,          'nome' => 'RG_CPF_Roberto_Santos.pdf'],
                    ['tipo' => DocumentoProcesso::TIPO_COMPROVANTE_RESIDENCIA, 'nome' => 'Comprovante_Residencia_Roberto.pdf'],
                    ['tipo' => DocumentoProcesso::TIPO_GRATUIDADE_JUSTICA,     'nome' => 'Declaracao_Hipossuficiencia.pdf'],
                    ['tipo' => DocumentoProcesso::TIPO_DEMAIS,                 'nome' => 'Extratos_Bancarios_Negativacao.pdf'],
                ],
                'docFlags' => ['peca' => true, 'procuracao' => true, 'identificacao' => true, 'residencia' => true, 'gratuidade' => true, 'demais' => true],
            ],
        ];

        $processos = [];
        foreach ($dados as $dado) {
            $p = new Processo();
            $p->setNumeroProcesso($dado['numero']);
            $p->setOrgaoJulgador($dado['orgaoJulgador']);
            $p->setSiglaTribunal($dado['sigla']);
            $p->setClasseProcessual($dado['classe']);
            $p->setAssuntoProcessual($dado['assunto']);
            $p->setSituacaoProcesso($dado['situacao']);
            $p->setInstancia($dado['instancia']);
            $p->setDataDistribuicao(new \DateTime($dado['distribuicao']));
            $p->setDataAtualizacao(new \DateTimeImmutable());

            // Doc flags
            $p->setDocPecaOk($dado['docFlags']['peca']);
            $p->setDocProcuracaoOk($dado['docFlags']['procuracao']);
            $p->setDocIdentificacaoOk($dado['docFlags']['identificacao']);
            $p->setDocComprovanteResidenciaOk($dado['docFlags']['residencia']);
            $p->setDocGratuidadeJusticaOk($dado['docFlags']['gratuidade']);
            $p->setDocDemaisOk($dado['docFlags']['demais']);

            // Partes
            foreach ($dado['partes'] as $parteData) {
                $parte = new ParteProcesso();
                $parte->setTipo($parteData['tipo']);
                $parte->setNome($parteData['nome']);
                $parte->setDocumento($parteData['documento']);
                $parte->setPapel($parteData['papel']);
                $p->addParte($parte);
                $manager->persist($parte);
            }

            // Movimentações
            foreach ($dado['movimentacoes'] as $movData) {
                $mov = new MovimentacaoProcesso();
                $mov->setDataMovimentacao(new \DateTime($movData['data']));
                $mov->setDescricao($movData['descricao']);
                $mov->setTipo($movData['tipo']);
                $mov->setOrgao($movData['orgao']);
                $p->addMovimentacao($mov);
                $manager->persist($mov);
            }

            // Documentos
            foreach ($dado['docs'] as $docData) {
                $doc = new DocumentoProcesso();
                $doc->setTipo($docData['tipo']);
                $doc->setNomeOriginal($docData['nome']);
                $doc->setCaminhoArquivo('processos/' . $p->getNumeroProcesso() . '/' . $docData['nome']);
                $doc->setMimeType('application/pdf');
                $doc->setTamanho(rand(80000, 500000));
                $p->addDocumento($doc);
                $manager->persist($doc);
            }

            $manager->persist($p);
            $processos[] = $p;
        }

        return $processos;
    }

    // -----------------------------------------------
    // TAREFAS
    // -----------------------------------------------
    private function loadTarefas(ObjectManager $manager, array $processos, array $users): void
    {
        $tarefasDados = [
            [
                'titulo'    => 'Elaborar petição de réplica - Proc. Trabalhista João Ferreira',
                'descricao' => 'Redigir réplica à contestação apresentada pela empresa XYZ, abordando os pontos controvertidos sobre o aviso prévio e as horas extras.',
                'prazo'     => '+5 days',
                'status'    => Tarefa::STATUS_PENDENTE,
                'processo'  => $processos[0],
                'atribuicoes' => [
                    ['usuario' => $users[3], 'status' => AtribuicaoTarefa::STATUS_PENDENTE],
                ],
                'mensagens' => [
                    ['usuario' => $users[1], 'texto' => 'Lucas, por favor elabore a réplica seguindo o modelo do escritório. Foco nos pontos de horas extras e aviso prévio.'],
                    ['usuario' => $users[3], 'texto' => 'Entendido, Dra. Fernanda. Vou iniciar hoje e envio para revisão até quinta-feira.'],
                ],
            ],
            [
                'titulo'    => 'Agendar perícia médica - Proc. Trabalhista',
                'descricao' => 'Verificar junto ao perito judicial indicado pelo juízo a disponibilidade de agenda e informar ao cliente com ao menos 5 dias de antecedência.',
                'prazo'     => '+3 days',
                'status'    => Tarefa::STATUS_EM_REVISAO,
                'processo'  => $processos[0],
                'atribuicoes' => [
                    ['usuario' => $users[4], 'status' => AtribuicaoTarefa::STATUS_EM_REVISAO],
                ],
                'mensagens' => [
                    ['usuario' => $users[1], 'texto' => 'Ana Paula, pode entrar em contato com o perito judicial e agendar a perícia?'],
                    ['usuario' => $users[4], 'texto' => 'Já realizei o contato. O perito tem disponibilidade para a próxima terça ou quarta-feira.'],
                ],
            ],
            [
                'titulo'    => 'Minutar acordo de separação - Proc. Família',
                'descricao' => 'Elaborar minuta do acordo de separação consensual conforme alinhado na última reunião com a cliente Maria Silva. Incluir cláusulas de guarda compartilhada, alimentos e partilha do imóvel.',
                'prazo'     => '+10 days',
                'status'    => Tarefa::STATUS_PENDENTE,
                'processo'  => $processos[1],
                'atribuicoes' => [
                    ['usuario' => $users[2], 'status' => AtribuicaoTarefa::STATUS_PENDENTE],
                ],
                'mensagens' => [
                    ['usuario' => $users[0], 'texto' => 'Marcelo, favor elaborar a minuta do acordo conforme reunião de ontem com a Maria.'],
                ],
            ],
            [
                'titulo'    => 'Preparar memoriais para julgamento - Apelação Roberto Santos',
                'descricao' => 'Elaborar memoriais para o julgamento da apelação na 5ª Câmara, reforçando os argumentos sobre a ilegalidade da negativação e o dano moral presumido.',
                'prazo'     => '+2 days',
                'status'    => Tarefa::STATUS_CONCLUIDA,
                'processo'  => $processos[2],
                'atribuicoes' => [
                    ['usuario' => $users[2], 'status' => AtribuicaoTarefa::STATUS_CONCLUIDA],
                ],
                'mensagens' => [
                    ['usuario' => $users[2], 'texto' => 'Memoriais finalizados e protocolados no sistema do TJRJ. Aguardando inclusão em pauta.'],
                    ['usuario' => $users[0], 'texto' => 'Ótimo trabalho, Marcelo. Tarefa concluída.'],
                ],
            ],
            [
                'titulo'    => 'Atualizar planilha de custas processuais - Geral',
                'descricao' => 'Levantar e atualizar os valores de custas e despesas processuais de todos os processos ativos para controle financeiro do escritório.',
                'prazo'     => '+7 days',
                'status'    => Tarefa::STATUS_PENDENTE,
                'processo'  => null,
                'atribuicoes' => [
                    ['usuario' => $users[4], 'status' => AtribuicaoTarefa::STATUS_PENDENTE],
                ],
                'mensagens' => [],
            ],
        ];

        foreach ($tarefasDados as $dado) {
            $tarefa = new Tarefa();
            $tarefa->setTitulo($dado['titulo']);
            $tarefa->setDescricao($dado['descricao']);
            $tarefa->setPrazo(
                $dado['prazo'] ? new \DateTimeImmutable($dado['prazo']) : null
            );
            $tarefa->setStatus($dado['status']);
            $tarefa->setProcesso($dado['processo']);

            if ($dado['status'] === Tarefa::STATUS_CONCLUIDA) {
                $tarefa->setDataConclusao(new \DateTimeImmutable('-1 day'));
            }

            foreach ($dado['atribuicoes'] as $atribData) {
                $atrib = new AtribuicaoTarefa();
                $atrib->setUsuario($atribData['usuario']);
                $atrib->setStatus($atribData['status']);
                if ($atribData['status'] === AtribuicaoTarefa::STATUS_EM_REVISAO) {
                    $atrib->setDataEnvioRevisao(new \DateTimeImmutable('-2 hours'));
                }
                $tarefa->addAtribuicao($atrib);
                $manager->persist($atrib);
            }

            foreach ($dado['mensagens'] as $msgData) {
                $msg = new TarefaMensagem();
                $msg->setUsuario($msgData['usuario']);
                $msg->setMensagem($msgData['texto']);
                $tarefa->addMensagem($msg);
                $manager->persist($msg);
            }

            $manager->persist($tarefa);
        }
    }

    // -----------------------------------------------
    // CHAMADOS (SERVICE DESK)
    // -----------------------------------------------
    private function loadChamados(ObjectManager $manager, array $users): void
    {
        $dados = [
            [
                'titulo'       => 'Erro ao acessar o sistema de peticionamento eletrônico',
                'descricao'    => 'Ao tentar acessar o PJe, o sistema exibe a mensagem "Certificado digital não reconhecido". O problema ocorre no computador da sala 3. Já tentei reinstalar o driver do token, mas sem sucesso.',
                'categoria'    => Chamado::CATEGORIA_SOFTWARE,
                'prioridade'   => Chamado::PRIORIDADE_ALTA,
                'status'       => Chamado::STATUS_EM_ANDAMENTO,
                'departamento' => 'Jurídico',
                'solicitante'  => $users[1],
                'responsavel'  => $users[5],
            ],
            [
                'titulo'       => 'Impressora da secretaria não imprime documentos maiores que 10 páginas',
                'descricao'    => 'A impressora HP LaserJet da secretaria trava e reinicia ao tentar imprimir documentos com mais de 10 páginas. Já foram tentadas impressões de vários arquivos PDF diferentes e o problema persiste.',
                'categoria'    => Chamado::CATEGORIA_IMPRESSORA,
                'prioridade'   => Chamado::PRIORIDADE_MEDIA,
                'status'       => Chamado::STATUS_ABERTO,
                'departamento' => 'Secretaria',
                'solicitante'  => $users[4],
                'responsavel'  => null,
            ],
            [
                'titulo'       => 'Solicitação de acesso ao módulo financeiro',
                'descricao'    => 'Preciso de acesso ao módulo financeiro do sistema para consultar as custas processuais. Meu usuário atual não possui essa permissão.',
                'categoria'    => Chamado::CATEGORIA_ACESSO,
                'prioridade'   => Chamado::PRIORIDADE_BAIXA,
                'status'       => Chamado::STATUS_RESOLVIDO,
                'departamento' => 'Jurídico',
                'solicitante'  => $users[3],
                'responsavel'  => $users[5],
            ],
            [
                'titulo'       => 'E-mail institucional retornando mensagens não entregues',
                'descricao'    => 'Desde ontem pela manhã, e-mails enviados para clientes externos retornam com erro "550 - Mailbox unavailable". Já verifiquei que os endereços estão corretos. Outros usuários relatam o mesmo problema.',
                'categoria'    => Chamado::CATEGORIA_EMAIL,
                'prioridade'   => Chamado::PRIORIDADE_CRITICA,
                'status'       => Chamado::STATUS_EM_ANDAMENTO,
                'departamento' => 'Geral',
                'solicitante'  => $users[0],
                'responsavel'  => $users[5],
            ],
            [
                'titulo'       => 'Lentidão na rede Wi-Fi da sala de reuniões',
                'descricao'    => 'A rede sem fio na sala de reuniões está com velocidade muito baixa, dificultando videoconferências com clientes. O problema ocorre há três dias. A rede cabeada funciona normalmente.',
                'categoria'    => Chamado::CATEGORIA_REDE,
                'prioridade'   => Chamado::PRIORIDADE_MEDIA,
                'status'       => Chamado::STATUS_FECHADO,
                'departamento' => 'Infraestrutura',
                'solicitante'  => $users[2],
                'responsavel'  => $users[5],
            ],
        ];

        foreach ($dados as $dado) {
            $chamado = new Chamado();
            $chamado->setTitulo($dado['titulo']);
            $chamado->setDescricao($dado['descricao']);
            $chamado->setCategoria($dado['categoria']);
            $chamado->setPrioridade($dado['prioridade']);
            $chamado->setStatus($dado['status']);
            $chamado->setDepartamento($dado['departamento']);
            $chamado->setSolicitante($dado['solicitante']);
            $chamado->setResponsavel($dado['responsavel']);
            $manager->persist($chamado);
        }
    }

    // -----------------------------------------------
    // EVENTOS DE AGENDA
    // FIX: datas com horário construídas via (new \DateTimeImmutable('+N days'))->setTime(H, M)
    //      pois o formato '+7 days 09:00' é inválido no PHP e causaria exceção.
    // -----------------------------------------------
    private function loadEventos(ObjectManager $manager, array $users): void
    {
        $dados = [
            [
                'titulo'      => 'Audiência Trabalhista - João Ferreira x Empresa XYZ',
                'descricao'   => 'Audiência de instrução e julgamento. Proc. 0001234-56.2024.5.02.0001. Comparecer com documentos do cliente.',
                'inicio'      => (new \DateTimeImmutable('+7 days'))->setTime(9, 0),
                'fim'         => (new \DateTimeImmutable('+7 days'))->setTime(11, 0),
                'local'       => '1ª Vara do Trabalho de São Paulo - Fórum Trabalhista de São Paulo',
                'status'      => Evento::STATUS_AGENDADO,
                'cor'         => Evento::COR_AZUL,
                'diaInteiro'  => false,
                'recorrente'  => false,
                'criador'     => $users[1],
                'participantes' => [$users[3]],
            ],
            [
                'titulo'      => 'Reunião com cliente - Maria Silva (Divórcio)',
                'descricao'   => 'Reunião para apresentar a minuta do acordo e alinhar estratégia para a audiência de mediação.',
                'inicio'      => (new \DateTimeImmutable('+3 days'))->setTime(14, 0),
                'fim'         => (new \DateTimeImmutable('+3 days'))->setTime(15, 30),
                'local'       => 'Escritório - Sala de Reuniões 1',
                'status'      => Evento::STATUS_AGENDADO,
                'cor'         => Evento::COR_VERDE,
                'diaInteiro'  => false,
                'recorrente'  => false,
                'criador'     => $users[2],
                'participantes' => [$users[4]],
            ],
            [
                'titulo'      => 'Prazo: Contrarrazões de Apelação - Banco Meridional',
                'descricao'   => 'Último dia para protocolar contrarrazões no TJRJ. Proc. 9876543-21.2022.8.19.0001.',
                'inicio'      => (new \DateTimeImmutable('+1 day'))->setTime(23, 59),
                'fim'         => (new \DateTimeImmutable('+1 day'))->setTime(23, 59),
                'local'       => null,
                'status'      => Evento::STATUS_AGENDADO,
                'cor'         => Evento::COR_VERMELHO,
                'diaInteiro'  => true,
                'recorrente'  => false,
                'criador'     => $users[2],
                'participantes' => [],
            ],
            [
                'titulo'          => 'Reunião de alinhamento semanal - Equipe Jurídica',
                'descricao'       => 'Reunião semanal de alinhamento de casos, distribuição de tarefas e atualização de prazos.',
                'inicio'          => (new \DateTimeImmutable('next monday'))->setTime(8, 30),
                'fim'             => (new \DateTimeImmutable('next monday'))->setTime(9, 30),
                'local'           => 'Escritório - Sala de Reuniões 2',
                'status'          => Evento::STATUS_AGENDADO,
                'cor'             => Evento::COR_ROXO,
                'diaInteiro'      => false,
                'recorrente'      => true,
                'tipoRecorrencia' => 'semanal',
                'fimRecorrencia'  => new \DateTimeImmutable('+6 months'),
                'criador'         => $users[0],
                'participantes'   => [$users[1], $users[2], $users[3], $users[4]],
            ],
            [
                'titulo'      => 'Perícia Médica - Proc. Trabalhista (João Ferreira)',
                'descricao'   => 'Acompanhar cliente na perícia médica judicial. Local: consultório do perito Dr. Rogério Pimentel.',
                'inicio'      => (new \DateTimeImmutable('+10 days'))->setTime(10, 0),
                'fim'         => (new \DateTimeImmutable('+10 days'))->setTime(12, 0),
                'local'       => 'Rua Consolação, 2400, Sala 54 - São Paulo/SP',
                'status'      => Evento::STATUS_AGENDADO,
                'cor'         => Evento::COR_CIANO,
                'diaInteiro'  => false,
                'recorrente'  => false,
                'criador'     => $users[1],
                'participantes' => [],
            ],
            [
                'titulo'      => 'Consulta - Horizonte Construções (Contrato de Fornecimento)',
                'descricao'   => 'Análise de contrato de fornecimento de materiais no valor de R$ 2.300.000,00. Verificar cláusulas de responsabilidade e penalidades.',
                'inicio'      => (new \DateTimeImmutable('-5 days'))->setTime(15, 0),
                'fim'         => (new \DateTimeImmutable('-5 days'))->setTime(17, 0),
                'local'       => 'Escritório - Sala de Reuniões 1',
                'status'      => Evento::STATUS_CONCLUIDO,
                'cor'         => Evento::COR_VERDE,
                'diaInteiro'  => false,
                'recorrente'  => false,
                'criador'     => $users[1],
                'participantes' => [$users[2]],
            ],
        ];

        foreach ($dados as $dado) {
            $evento = new Evento();
            $evento->setTitulo($dado['titulo']);
            $evento->setDescricao($dado['descricao']);
            $evento->setDataInicio($dado['inicio']);
            $evento->setDataFim($dado['fim']);
            $evento->setLocal($dado['local']);
            $evento->setStatus($dado['status']);
            $evento->setCor($dado['cor']);
            $evento->setDiaInteiro($dado['diaInteiro']);
            $evento->setRecorrente($dado['recorrente']);
            $evento->setCriador($dado['criador']);

            if (!empty($dado['tipoRecorrencia'])) {
                $evento->setTipoRecorrencia($dado['tipoRecorrencia']);
                $evento->setFimRecorrencia($dado['fimRecorrencia']);
            }

            foreach ($dado['participantes'] as $participante) {
                $evento->addParticipante($participante);
            }

            $manager->persist($evento);
        }
    }
}