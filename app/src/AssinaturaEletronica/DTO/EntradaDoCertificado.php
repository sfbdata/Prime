<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\AssinaturaEletronica\Exception\AcaoRecusada;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * O corpo do "Assinar" de quem assina gov.br ou ICP na tela pública (etapa 5, bloco 5C): multipart com `nome`, `cpf`,
 * `aceite` e `arquivo` (o PDF assinado fora do BlueJus). Não tem rubrica nem código: a prova é o arquivo.
 *
 * O nome e o CPF seguem as regras da Simples ({@see EntradaDaAssinatura}): espaços seguidos viram um, o CPF fica só com
 * os dígitos. O `aceite` é a caixa de confirmação de leitura: `1`, `true`, `on` ou `yes` marcam; ausente ou outro valor,
 * não. Sem o arquivo, o PDF é nulo, e quem confere decide na ordem do desenho (dc:386-388).
 */
final readonly class EntradaDoCertificado
{
    private function __construct(
        public string $nome,
        public string $cpf,
        public bool $aceite,
        public ?UploadedFile $pdf,
    ) {
    }

    /**
     * @param array<mixed> $campos  os campos de texto do multipart
     * @param mixed        $arquivo o que veio no campo `arquivo` (só um arquivo enviado conta)
     *
     * @throws AcaoRecusada campo que não é texto (lista ou objeto)
     */
    public static function doFormulario(array $campos, mixed $arquivo): self
    {
        return new self(
            (string) preg_replace('/\s+/u', ' ', LeitorDeCampos::texto($campos, 'nome')),
            (string) preg_replace('/\D/', '', LeitorDeCampos::texto($campos, 'cpf')),
            LeitorDeCampos::booleano($campos, 'aceite'),
            $arquivo instanceof UploadedFile ? $arquivo : null,
        );
    }

    /** @throws AcaoRecusada "Informe o nome completo." */
    public function exigirNome(): void
    {
        EntradaDaAssinatura::exigirNomeCompleto($this->nome);
    }

    /** @throws AcaoRecusada "Informe um CPF válido." */
    public function exigirCpf(): void
    {
        EntradaDaAssinatura::exigirCpfValido($this->cpf);
    }
}
