<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * O formulário "Nova solicitação de trabalho externo" (três seções do desenho: SOLICITANTE, LOCAL E
 * PROCESSO, SERVIÇO). Os obrigatórios (*) só são cobrados no ENVIO; o rascunho aceita o que vier,
 * como no protótipo — por isso a validação usa o grupo `envio`.
 */
final class DadosDaSolicitacao
{
    public const TIPOS = [
        'Advocacia correspondente presencial', 'Audiência', 'Diligência em fórum ou tribunal',
        'Protocolo e acompanhamento processual', 'Cópias e digitalização de autos',
        'Despacho e contato institucional', 'Diligência em delegacia',
        'Acompanhamento de procedimento policial', 'Sustentação oral', 'Carta precatória',
        'Elaboração ou revisão de peça', 'Parecer e análise jurídica', 'Consulta processual presencial',
        'Retirada e entrega de documentos', 'Atividade administrativa processual', 'Outro serviço',
    ];

    public const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR',
        'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    public const PRIORIDADES = ['Normal', 'Alta', 'Urgente'];

    #[Assert\NotBlank(groups: ['envio'])]
    #[Assert\Length(max: 255, groups: ['Default', 'envio'])]
    public string $escritorio = '';

    #[Assert\NotBlank(groups: ['envio'])]
    #[Assert\Email(groups: ['Default', 'envio'])]
    #[Assert\Length(max: 180, groups: ['Default', 'envio'])]
    public string $email = '';

    #[Assert\Length(max: 30, groups: ['Default', 'envio'])]
    public string $telefone = '';

    #[Assert\Choice(choices: self::UFS, groups: ['Default', 'envio'])]
    public string $ufOrigem = 'DF';

    #[Assert\Length(max: 120, groups: ['Default', 'envio'])]
    public string $cidadeOrigem = '';

    public bool $emNomeDeOutro = false;

    #[Assert\NotBlank(groups: ['envio'])]
    #[Assert\Length(max: 255, groups: ['Default', 'envio'])]
    public string $advogado = '';

    #[Assert\NotBlank(groups: ['envio'])]
    #[Assert\Length(max: 20, groups: ['Default', 'envio'])]
    public string $oab = '';

    #[Assert\Choice(choices: self::UFS, groups: ['Default', 'envio'])]
    public string $oabUf = 'DF';

    #[Assert\Choice(choices: self::UFS, groups: ['Default', 'envio'])]
    public string $ufExecucao = 'DF';

    #[Assert\NotBlank(groups: ['envio'])]
    #[Assert\Length(max: 120, groups: ['Default', 'envio'])]
    public string $cidadeExecucao = '';

    #[Assert\Length(max: 255, groups: ['Default', 'envio'])]
    public string $orgao = '';

    #[Assert\Length(max: 30, groups: ['Default', 'envio'])]
    public string $processo = '';

    #[Assert\Length(max: 2000, groups: ['Default', 'envio'])]
    public string $partes = '';

    #[Assert\Choice(choices: self::TIPOS, groups: ['Default', 'envio'])]
    public string $tipoServico = self::TIPOS[0];

    #[Assert\Choice(choices: self::PRIORIDADES, groups: ['Default', 'envio'])]
    public string $prioridade = 'Normal';

    #[Assert\NotNull(groups: ['envio'])]
    public ?\DateTimeImmutable $prazoFinal = null;

    public ?\DateTimeImmutable $dataPretendida = null;

    #[Assert\NotBlank(groups: ['envio'])]
    #[Assert\Length(max: 10000, groups: ['Default', 'envio'])]
    public string $descricao = '';

    #[Assert\Length(max: 10000, groups: ['Default', 'envio'])]
    public string $objetivo = '';

    #[Assert\Length(max: 10000, groups: ['Default', 'envio'])]
    public string $instrucoes = '';

    public bool $urgente = false;

    /**
     * Lê o corpo enviado pelo modal. Campo ausente fica no valor padrão; data inválida vira null
     * (e o envio acusa "Preencha os campos obrigatórios").
     *
     * @param array<string, mixed> $dados
     */
    public static function deArray(array $dados): self
    {
        $d = new self();
        $texto = static fn (string $k): string => trim((string) ($dados[$k] ?? ''));

        foreach (['escritorio', 'email', 'telefone', 'cidadeOrigem', 'advogado', 'oab', 'cidadeExecucao', 'orgao', 'processo', 'partes', 'descricao', 'objetivo', 'instrucoes'] as $campo) {
            $d->{$campo} = $texto($campo);
        }
        foreach (['ufOrigem', 'oabUf', 'ufExecucao', 'tipoServico', 'prioridade'] as $campo) {
            if ($texto($campo) !== '') {
                $d->{$campo} = $texto($campo);
            }
        }
        $d->emNomeDeOutro = filter_var($dados['emNomeDeOutro'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $d->urgente = filter_var($dados['urgente'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $d->prazoFinal = self::data($texto('prazoFinal'), '!Y-m-d');
        $d->dataPretendida = self::data($texto('dataPretendida'), 'Y-m-d\TH:i');

        return $d;
    }

    private static function data(string $valor, string $formato): ?\DateTimeImmutable
    {
        if ($valor === '') {
            return null;
        }
        $data = \DateTimeImmutable::createFromFormat($formato, $valor);

        return $data !== false && $data->format($formato === '!Y-m-d' ? 'Y-m-d' : 'Y-m-d\TH:i') === $valor ? $data : null;
    }
}
