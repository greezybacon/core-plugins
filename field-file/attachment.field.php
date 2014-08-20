<?php

class FileUploadField extends FormField {
    static $widget = 'FileUploadWidget';

    protected $attachments;

    function __construct() {
        call_user_func_array(array('parent', '__construct'), func_get_args());
    }

    function getConfigurationOptions() {
        // Compute size selections
        $sizes = array('262144' => '— Small —');
        $next = 512 << 10;
        $max = strtoupper(ini_get('upload_max_filesize'));
        $limit = (int) $max;
        if (!$limit) $limit = 2 << 20; # 2M default value
        elseif (strpos($max, 'K')) $limit <<= 10;
        elseif (strpos($max, 'M')) $limit <<= 20;
        elseif (strpos($max, 'G')) $limit <<= 30;
        while ($next <= $limit) {
            // Select the closest, larger value (in case the
            // current value is between two)
            $diff = $next - $config['max_file_size'];
            $sizes[$next] = Format::file_size($next);
            $next *= 2;
        }
        // Add extra option if top-limit in php.ini doesn't fall
        // at a power of two
        if ($next < $limit * 2)
            $sizes[$limit] = Format::file_size($limit);

        return array(
            'size' => new ChoiceField(array(
                'label'=>'Maximum File Size',
                'hint'=>'Maximum size of a single file uploaded to this field',
                'default'=>'262144',
                'choices'=>$sizes
            )),
            'extensions' => new TextareaField(array(
                'label'=>'Allowed Extensions',
                'hint'=>'Enter allowed file extensions separated by a comma.
                e.g .doc, .pdf. To accept all files enter wildcard
                <b><i>.*</i></b> — i.e dotStar (NOT Recommended).',
                'default'=>'.doc, .pdf, .jpg, .jpeg, .gif, .png, .xls, .docx, .xlsx, .txt',
                'configuration'=>array('html'=>false, 'rows'=>2),
            )),
            'max' => new TextboxField(array(
                'label'=>'Maximum Files',
                'hint'=>'Users cannot upload more than this many files',
                'default'=>false,
                'required'=>false,
                'validator'=>'number',
                'configuration'=>array('size'=>4, 'length'=>4),
            ))
        );
    }

    static function _upload($id) {
        if (!$field = DynamicFormField::lookup($id)) 
            Http::response(400, 'No such field');

        $impl = $field->getImpl();
        if (!$impl instanceof static)
            Http::response(400, 'Upload to a non file-field');

        return $impl->upload();
    }

    function upload() {
        $config = $this->getConfiguration();

        $files = AttachmentFile::format($_FILES['upload']);
        if (count($files) != 1)
            Http::response(400, 'Send one file at a time');
        $file = array_shift($files);
        $file['name'] = urldecode($file['name']);

        // TODO: Check allowed type / size.
        //       Return HTTP/413, 415, 417 or similar

        if (!($id = AttachmentFile::upload($file)))
            Http::response(500, 'Unable to store file');

        Http::response(200, $id);
    }

    function getFiles() {
        if (!isset($this->attachments) && ($a = $this->getAnswer())
            && ($e = $a->getEntry()) && ($e->get('id'))
        ) {
            $this->attachments = new GenericAttachments(
                // Combine the field and entry ids to make the key
                sprintf('%u', crc32('E'.$this->get('id').$e->get('id'))),
                'E');
        }
        return $this->attachments ? $this->attachments->getAll() : array();
    }

    // When the field is saved to database, encode the ID listing as a json
    // array. Then, inspect the difference between the files actually
    // attached to this field
    function to_database($value) {
        $this->getFiles();
        if (isset($this->attachments)) {
            $ids = array();
            // Handle deletes
            foreach ($this->attachments->getAll() as $f) {
                if (!in_array($f['id'], $value))
                    $this->attachments->delete($f['id']);
                else
                    $ids[] = $f['id'];
            }
            // Handle new files
            foreach ($value as $id) {
                if (!in_array($id, $ids))
                    $this->attachments->upload($id);
            }
        }
        return JsonDataEncoder::encode($value);
    }

    function parse($value) {
        // Values in the database should be integer file-ids
        return array_map(function($e) { return (int) $e; },
            $value ?: array());
    }

    function to_php($value) {
        return JsonDataParser::decode($value);
    }
}

class FileUploadWidget extends Widget {
    static $media = array(
        'js' => array(
            'ajax.php/filefield/js/jquery.filedrop.js',
            'ajax.php/filefield/js/filedrop.field.js',
        ),
        'css' => array(
            'ajax.php/filefield/css/filedrop.css',
        ),
    );

    function render($how) {
        $config = $this->field->getConfiguration();
        $name = $this->field->getFormName();
        $attachments = $this->field->getFiles();
        $files = array();
        foreach ($this->value ?: array() as $id) {
            $found = false;
            foreach ($attachments as $f) {
                if ($f['id'] == $id) {
                    $files[] = $f;
                    $found = true;
                    break;
                }
            }
            if (!$found && ($file = AttachmentFile::lookup($id))) {
                $files[] = array(
                    'id' => $file->getId(),
                    'name' => $file->getName(),
                    'type' => $file->getType(),
                    'size' => $file->getSize(),
                );
            }
        }
        ?><div id="filedrop-<?php echo $name;
            ?>" class="filedrop">
            <div class="files"></div>
            <div class="dropzone"><i class="icon-upload"></i>
            Drop files here or <a href="#" class="manual">choose
            them</a></div>
            </div>
        <input type="file" id="file-<?php echo $name;
            ?>" name="<?php echo $name; ?>" style="display:none;"/>
        <script type="text/javascript">
        $(function(){$('#filedrop-<?php echo $name; ?> .dropzone').filedropbox({
          url: 'ajax.php/filefield/upload/<?php echo $this->field->get('id') ?>',
          link: $('#filedrop-<?php echo $name; ?>').find('a.manual'),
          paramname: 'upload[]',
          fallback_id: 'file-<?php echo $name; ?>',
          allowedfileextensions: '<?php echo $config['extensions'];
          ?>'.split(/,\s*/),
          maxfiles: <?php echo $config['max'] ?: 20; ?>,
          maxfilesize: <?php echo $config['filesize'] ?: 1048576 / 1048576; ?>,
          name: '<?php echo $name; ?>[]',
          files: <?php echo JsonDataEncoder::encode($files); ?>
        });});
        </script>
<?php
    }

    function getValue() {
        $data = $this->field->getSource();
        // If no value was sent, assume an empty list
        if ($data && is_array($data) && !isset($data[$this->name]))
            return array();
        return parent::getValue();
    }
}

class FileFieldPlugin extends Plugin {
    static $API_VERSION = 4;

    function bootstrap() {
        FormField::addFieldTypes('Basic Fields', function() {
            return array('files' => array(__('File Uploads'), 'FileUploadField'));
        });
        $urls = function($dispatcher) {
            $static = function($file, $type=false) {
                if ($type)
                    header('Content-Type: '.$type);
                $file = dirname(__file__).'/'.$file;
                if (file_exists($file))
                    fpassthru(fopen($file, 'r'));
            };
            $dispatcher->append(
                url('^/filefield/', patterns('',
                    url_get('^(js/.+\.js)$', $static,
                        array('text/javascript')),
                    url_get('^(css/.+\.css)$', $static,
                        array('text/css')),
                    url_post('^upload/(?P<id>\d+)$',
                    function($id) {
                        return FileUploadField::_upload($id);
                    })
                ))
            );
        };
        Signal::connect('ajax.scp', $urls);
        Signal::connect('ajax.client', $urls);
    }
}
