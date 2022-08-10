<?php
/**
 * @author Valentin Konusov <rlng-krsk@yandex.ru>
 * @author Alexey Samoylov <alexey.samoylov@gmail.com>
 * @link http://yiidreamteam.com/yii2/upload-behavior
 */
namespace yiidreamteam\upload;

use common\classes\Utils;
use Imagine\Image\Box;
use Imagine\Image\ManipulatorInterface;
use PHPThumb\GD;
use Yii;
use yii\base\InvalidCallException;
use yii\base\Model;
use yii\db\ActiveRecord;
use yii\db\BaseActiveRecord;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\helpers\VarDumper;
use yii\imagine\Image;
use yii\web\UploadedFile;
use yiidreamteam\upload\exceptions\FileUploadException;

/**
 * Class FileUploadBehavior
 *
 * @property ActiveRecord $owner
 */
class FileUploadBehavior extends \yii\base\Behavior
{
    const EVENT_AFTER_FILE_SAVE = 'afterFileSave';

    /** @var string Name of attribute which holds the attachment. */
    public $attribute = 'upload';
    public $attributeExtension = 'extension';

    /** @var string Path template to use in storing files.5 */
    public $filePath = '@webroot/uploads/[[pk]].[[extension]]';

    /** @var string Where to store images. */
    public $fileUrl = '/uploads/[[pk]].[[extension]]';

    /**
     * @var string Attribute used to link owner model with it's parent
     * @deprecated Use attribute_xxx placeholder instead
     */
    public $parentRelationAttribute;

    /** @var \yii\web\UploadedFile */
    protected $file;


    public $createThumbsOnSave = true;
    public $createThumbsOnRequest = false;

    /** @var array Thumbnail profiles, array of [width, height, ... PHPThumb options] */
    public $thumbs = [];

    /** @var string Path template for thumbnails. Please use the [[profile]] placeholder. */
    public $thumbPath = '@webroot/images/[[profile]]_[[pk]].[[extension]]';
    /** @var string Url template for thumbnails. */
    public $thumbUrl = '/images/[[profile]]_[[pk]].[[extension]]';

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
            ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
        ];
    }

    /**
     * Before validate event.
     */
    public function beforeValidate()
    {

        if ($this->owner->{$this->attribute} instanceof UploadedFile) {
            $this->file = $this->owner->{$this->attribute};
//            if($this->owner->hasProperty('imagem_base64')){
////                $this->owner->imagem_base64 = file_get_contents($this->file->tempName);
////            }
            $this->owner->extensao = $this->file->extension;
            return;
        }

        $this->file = UploadedFile::getInstance($this->owner, $this->attribute);

        if (empty($this->file)) {
            $this->file = UploadedFile::getInstanceByName($this->attribute);
        }

        if ($this->file instanceof UploadedFile) {
            $this->owner->{$this->attribute} = $this->file;
//            if($this->owner->hasProperty('imagem_base64')){
//                $this->owner->imagem_base64 = file_get_contents($this->file->tempName);
//            }
            $this->owner->extensao = $this->file->extension;
        }
    }

    /**
     * @param string $attribute
     * @param string $profile
     * @return string
     */
    public function getThumbFilePath($attribute, $profile = 'thumb')
    {
        $behavior = static::getInstance($this->owner, $attribute);
        return $behavior->resolveProfilePath($behavior->thumbPath, $profile);
    }

    /**
     * @param string $attribute
     * @param string $profile
     * @param string|null $emptyUrl
     * @return string|null
     * @throws \yii\base\Exception
     */
    public function getThumbFileUrl($attribute, $profile = 'thumb', $emptyUrl = null)
    {

        if (!$this->owner->{$attribute}) {
            return $emptyUrl;
        }

        $encoded_full_url = urlencode($this->owner->{$attribute});
        $full_thumb_size = env('STATIC_URL').'/unsafe/200x200/'.$encoded_full_url;
        return $full_thumb_size;
        //return $this->owner->{$attribute};
//
//        $behavior = static::getInstance($this->owner, $attribute);
//
//        if ($behavior->createThumbsOnRequest) {
//            $behavior->createThumbs();
//        }
//
//        return $behavior->resolveProfilePath($behavior->thumbUrl, $profile);
    }

    /**
     * Creates image thumbnails
     * @throws \yii\base\Exception
     */
    public function createThumbs()
    {
        $path = $this->getUploadedFilePath($this->attribute);
        foreach ($this->thumbs as $profile => $config) {
            $thumbPath = static::getThumbFilePath($this->attribute, $profile);
            if (is_file($path) && !is_file($thumbPath)) {
                FileHelper::createDirectory(pathinfo($thumbPath, PATHINFO_DIRNAME), 0775, true);
                Image::getImagine()->open($path)
                    ->thumbnail(new Box($config['width'], $config['height']), ManipulatorInterface::THUMBNAIL_INSET)
                    ->save($thumbPath , ['quality' => 100]);
            }
        }
    }

    /**
     * Resolves profile path for thumbnail profile.
     *
     * @param string $path
     * @param string $profile
     * @return string
     */
    public function resolveProfilePath($path, $profile)
    {
        $path = $this->resolvePath($path);
        return preg_replace_callback('|\[\[([\w\_/]+)\]\]|', function ($matches) use ($profile) {
            $name = $matches[1];
            switch ($name) {
                case 'profile':
                    return $profile;
            }
            return '[[' . $name . ']]';
        }, $path);
    }

    /**
     * Before save event.
     *
     * @throws \yii\base\InvalidConfigException
     */
    public function beforeSave()
    {
        if ($this->file instanceof UploadedFile) {

            if (true !== $this->owner->isNewRecord) {
                /** @var ActiveRecord $oldModel */
                $oldModel = $this->owner->findOne($this->owner->primaryKey);
                $behavior = static::getInstance($oldModel, $this->attribute);
                $behavior->cleanFiles();
            }


            $this->owner->{$this->attribute} = implode('.',
                array_filter([$this->file->baseName, $this->file->extension])
            );
        } else {
            if (true !== $this->owner->isNewRecord && empty($this->owner->{$this->attribute})) {
                $this->owner->{$this->attribute} = ArrayHelper::getValue($this->owner->oldAttributes, $this->attribute,
                    null);
            }
        }

        $this->owner->{$this->attributeExtension} = $this->file->extension;
    }

    /**
     * Returns behavior instance for specified object and attribute
     *
     * @param Model $model
     * @param string $attribute
     * @return static
     */
    public static function getInstance(Model $model, $attribute)
    {
        foreach ($model->behaviors as $behavior) {
            if ($behavior instanceof self && $behavior->attribute == $attribute) {
                return $behavior;
            }
        }

        throw new InvalidCallException('Missing behavior for attribute ' . VarDumper::dumpAsString($attribute));
    }

    /**
     * Removes files associated with attribute
     */
    public function cleanFiles()
    {

        // Instantiate an Amazon S3 client.
        $credentials = new \Aws\Credentials\Credentials(env('AWS_ACCESS_KEY'), env('AWS_SECRET_ACCESS_KEY'));
        $s3 = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region'  => env('AWS_REGION'),
            'credentials' => $credentials
        ]);

        $url = $this->owner->{$this->attribute};

        $bucket = env('AWS_S3_BUCKET');

        try {
            $result = $s3->deleteObject([
                'Bucket' => $bucket, // REQUIRED
                'Key' => $url, // REQUIRED
            ]);
        } catch (\Exception $e) {
            dump($e->getMessage());
        }

    }

    /**
     * Replaces all placeholders in path variable with corresponding values
     *
     * @param string $path
     * @return string
     */
    public function resolvePath($path)
    {
        $path = Yii::getAlias($path);

        $pi = pathinfo($this->owner->{$this->attribute});
        $fileName = ArrayHelper::getValue($pi, 'filename');
        $extension = strtolower(ArrayHelper::getValue($pi, 'extension'));

        return preg_replace_callback('|\[\[([\w\_/]+)\]\]|', function ($matches) use ($fileName, $extension) {
            $name = $matches[1];
            switch ($name) {
                case 'extension':
                    return $extension;
                case 'filename':
                    return $fileName;
                case 'basename':
                    return implode('.', array_filter([$fileName, $extension]));
                case 'app_root':
                    return Yii::getAlias('@app');
                case 'web_root':
                    return Yii::getAlias('@webroot');
                case 'base_url':
                    return Yii::getAlias('@web');
                case 'model':
                    $r = new \ReflectionClass($this->owner->className());
                    return lcfirst($r->getShortName());
                case 'attribute':
                    return lcfirst($this->attribute);
                case 'id':
                case 'pk':
                    $pk = implode('_', $this->owner->getPrimaryKey(true));
                    return lcfirst($pk);
                case 'id_path':
                    return static::makeIdPath($this->owner->getPrimaryKey());
                case 'parent_id':
                    return $this->owner->{$this->parentRelationAttribute};
            }
            if (preg_match('|^attribute_(\w+)$|', $name, $am)) {
                $attribute = $am[1];
                return $this->owner->{$attribute};
            }
            if (preg_match('|^md5_attribute_(\w+)$|', $name, $am)) {
                $attribute = $am[1];
                return md5($this->owner->{$attribute});
            }
            return '[[' . $name . ']]';
        }, $path);
    }

    /**
     * @param integer $id
     * @return string
     */
    protected static function makeIdPath($id)
    {
        $id = is_array($id) ? implode('', $id) : $id;
        $length = 10;
        $id = str_pad($id, $length, '0', STR_PAD_RIGHT);

        $result = [];
        for ($i = 0; $i < $length; $i++) {
            $result[] = substr($id, $i, 1);
        }

        return implode('/', $result);
    }

    /**
     * After save event.
     * @throws \yii\base\Exception
     * @throws FileUploadException
     */
    public function afterSave()
    {
        if ($this->file instanceof UploadedFile !== true) {
            return;
        }

        $path = $this->getUploadedFilePath($this->attribute);
        FileHelper::createDirectory(pathinfo($path, PATHINFO_DIRNAME), 0775, true);

        // Instantiate an Amazon S3 client.
        $credentials = new \Aws\Credentials\Credentials(env('AWS_ACCESS_KEY'), env('AWS_SECRET_ACCESS_KEY'));
        $s3 = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region'  => env('AWS_REGION'),
            'credentials' => $credentials
        ]);

        // Se é imagem publica vai pra pasta correta
        $folder = 'documents';
        $acl = 'private';
        if(in_array($this->owner->tabela, ['categoria', 'produto', 'padrao_imagem'])) {
            $folder = 'images';
            $acl = 'public-read'; // se é imagem publica, entao ACL é public-read
        }

        // Sanitiza e prepara o nome do arquivo pra ser enviado
        $filename = date('YmdHis') .'_' . \common\classes\Utils::filter_filename($this->file->getBaseName()).'_'.Utils::generateRandomString(4);
        if(is_null($this->owner->empresa_id)) { // Se nulo por algum motivo ...
            $bucket_filename_path = $folder.'/'.$filename.'.'.$this->file->getExtension();
        } else {

            if($this->owner->tabela == 'padrao_imagem') {
                $bucket_filename_path = $folder.'/padrao/'.$filename.'.'.$this->file->getExtension();
            } else {
                $bucket_filename_path = $folder.'/'.$this->owner->empresa_id.'/'.$filename.'.'.$this->file->getExtension();
            }
        }

        try {

//            dump($bucket_filename_path);

            $result = $s3->putObject([
                'Bucket' => env('AWS_S3_BUCKET'),
                'Key'    => $bucket_filename_path,
                'Body'   => fopen($this->file->tempName, 'r'),
                'ACL'    => $acl,
                'ContentType' => mime_content_type($this->file->tempName)
            ]);
            //$result['ObjectURL']

            // Atualiza o proprio registro com o retorno da S3
            $this->owner->updateAttributes([
                $this->attribute => $bucket_filename_path,
            ]);

        } catch (\Aws\S3\Exception\S3Exception $e) {
//            dump($e->getMessage());
        }

        $this->owner->trigger(static::EVENT_AFTER_FILE_SAVE);
    }

    /**
     * Returns file path for attribute.
     *
     * @param string $attribute
     * @return string
     */
    public function getUploadedFilePath($attribute)
    {
        $behavior = static::getInstance($this->owner, $attribute);

        if (!$this->owner->{$attribute}) {
            return '';
        }

        return $behavior->resolvePath($behavior->filePath);
    }

    /**
     * Before delete event.
     */
    public function beforeDelete()
    {
        $this->cleanFiles();
    }

    /**
     * Returns file url for the attribute.
     *
     * @param string $attribute
     * @return string|null
     */
    public function getUploadedFileUrl($attribute)
    {
        if (!$this->owner->{$attribute}) {
            return null;
        }

        return $this->owner->{$attribute};
    }
}
