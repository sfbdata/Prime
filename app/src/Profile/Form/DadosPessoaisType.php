<?php
declare(strict_types=1);
namespace App\Profile\Form;

use App\Profile\DTO\DadosPessoaisInput;
use App\Profile\Enum\Atuacao;
use App\Profile\Enum\Genero;
use App\Profile\Enum\VinculoColaborador;
use App\Profile\Service\ChavePix;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DadosPessoaisType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nomeCompleto', TextType::class, [
                'label' => 'Nome completo',
                'attr'  => ['maxlength' => 255, 'placeholder' => 'Nome completo'],
            ])
            ->add('cpf', TextType::class, [
                'label'    => 'CPF',
                'required' => false,
                'attr'     => ['maxlength' => 14, 'placeholder' => '000.000.000-00', 'data-mask' => 'cpf'],
            ])
            ->add('dataNascimento', DateType::class, [
                'label'    => 'Data de nascimento',
                'required' => false,
                'widget'   => 'single_text',
                'html5'    => true,
            ])
            ->add('ctps', TextType::class, [
                'label'    => 'CTPS',
                'required' => false,
                'attr'     => ['maxlength' => 20, 'placeholder' => 'Nº da carteira de trabalho'],
            ])
            ->add('serie', TextType::class, [
                'label'    => 'Série',
                'required' => false,
                'attr'     => ['maxlength' => 20, 'placeholder' => 'Série da CTPS'],
            ]);
        if ($options['campos_do_perfil']) {
            $this->adicionarCamposDoPerfil($builder);
        }
    }

    /**
     * Só no Meu Perfil (desenho 07): frase, telefone, vínculo e datas do contrato. A tela de dados
     * pessoais do admin não tem esses campos e não pode apagá-los ao salvar.
     */
    private function adicionarCamposDoPerfil(FormBuilderInterface $builder): void
    {
        $builder
            ->add('status', TextType::class, [
                'label'    => 'Frase do perfil',
                'required' => false,
                'attr'     => ['maxlength' => 255, 'placeholder' => 'Uma frase que te representa'],
            ])
            ->add('telefone', TelType::class, [
                'label'    => 'Telefone / WhatsApp',
                'required' => false,
                'attr'     => ['maxlength' => 20, 'placeholder' => '(00) 00000-0000'],
            ])
            ->add('chavePixRecompensa', TextType::class, [
                'label'    => 'Chave PIX para recompensas',
                'required' => false,
                'attr'     => ['maxlength' => ChavePix::TAMANHO_MAXIMO, 'placeholder' => 'CPF, e-mail, celular ou chave aleatória', 'autocomplete' => 'off'],
            ]);
        $this->adicionarGeneroEAtuacao($builder);
        $this->adicionarCamposDoVinculo($builder);
    }

    /**
     * Desenho 05 de 21/10 (filtros Gênero e Cargo): declarados pela pessoa, nunca obrigatórios aqui. Gênero só com
     * Feminino e Masculino (D1 da spec genero-no-cadastro): o legado `nao_informado` não é oferecido nem aceito.
     */
    private function adicionarGeneroEAtuacao(FormBuilderInterface $builder): void
    {
        $builder
            ->add('genero', EnumType::class, [
                'class'           => Genero::class,
                'choices'         => Genero::oferecidos(),
                'label'           => 'Gênero',
                'required'        => false,
                'placeholder'     => 'Não preenchido',
                'choice_label'    => static fn (Genero $g): string => $g->rotulo(),
                'invalid_message' => Genero::ERRO_INFORME,
            ])
            ->add('atuacao', EnumType::class, [
                'class'        => Atuacao::class,
                'label'        => 'Atuação',
                'required'     => false,
                'placeholder'  => 'Não preenchida',
                'choice_label' => static fn (Atuacao $a): string => $a->rotulo(),
            ]);
    }

    /** Vínculo com o escritório e as datas do contrato (desenho 07, VINC e ED.info). */
    private function adicionarCamposDoVinculo(FormBuilderInterface $builder): void
    {
        $builder
            ->add('vinculo', EnumType::class, [
                'class'        => VinculoColaborador::class,
                'label'        => 'Vínculo com o escritório',
                'required'     => false,
                'expanded'     => true,
                'placeholder'  => false,
                'choice_label' => static fn (VinculoColaborador $v): string => $v->rotuloBotao(),
                // O modal troca a ajuda e os campos de data sem voltar ao servidor.
                'choice_attr'  => static fn (VinculoColaborador $v): array => [
                    'data-icone'         => $v->icone(),
                    'data-ajuda'         => $v->ajuda(),
                    'data-rotulo-inicio' => mb_strtoupper($v->rotuloInicio()),
                ],
            ])
            ->add('dataAdmissao', DateType::class, [
                'label'    => 'Data de admissão',
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
            ])
            ->add('contratoInicio', DateType::class, [
                'label'    => 'Início do contrato',
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
            ])
            ->add('cnpj', TextType::class, [
                'label'    => 'CNPJ (se houver)',
                'required' => false,
                'attr'     => ['maxlength' => 18, 'placeholder' => 'Somente números'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'       => DadosPessoaisInput::class,
            'csrf_token_id'    => 'profile_dados',
            'campos_do_perfil' => false,
        ]);
        $resolver->setAllowedTypes('campos_do_perfil', 'bool');
        // CPF com 11 números e CNPJ com 14 valem no modal do perfil (desenho 07); a tela do admin segue como era.
        $resolver->setDefault('validation_groups', static fn (\Symfony\Component\Form\FormInterface $form): array => $form->getConfig()->getOption('campos_do_perfil') ? ['Default', 'perfil'] : ['Default']);
    }
}
