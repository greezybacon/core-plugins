!function($) {
  "use strict";

  var FileDropbox = function(element, options) {
    this.$element = $(element);
    this.uploads = [];

    var events = {
      uploadStarted: $.proxy(this.uploadStarted, this),
      uploadFinished: $.proxy(this.uploadFinished, this),
      progressUpdated: $.proxy(this.progressUpdated, this),
      dragEnter: $.proxy(this.dragEnter, this),
      dragLeave: $.proxy(this.dragLeave, this),
      dragOver: $.proxy(this.dragOver, this),
      drop: $.proxy(this.dragLeave, this)
    };

    this.options = $.extend({}, $.fn.filedropbox.defaults, events, options);
    this.$element.filedrop(this.options);
    (this.options.files || []).forEach($.proxy(this.addNode, this));
  };

  FileDropbox.prototype = {
    drop: function(e) {
    },
    dragOver: function(box, e) {
        this.$element.css('background-color', 'rgba(0, 0, 0, 0.3)');
    },
    dragLeave: function(box, e) {
        this.$element.removeAttr('style');
        console.log('leave');
    },
    speedUpdated: function(i, file, speed) {
      this.uploads.some(function(e) {
        if (e.data('file') == file) {
          e.find('.upload-rate').text(speed);
          return true;
        }
      });
    },
    progressUpdated: function(i, file, value) {
      this.uploads.some(function(e) {
        if (e.data('file') == file) {
          e.find('.progress').show();
          e.find('.progress-bar')
            .width(value + '%')
            .attr({'aria-valuenow': value})
          if (value == 100)
            e.find('.progress-bar').addClass('progress-bar-active active') 
          return true;
        }
      });
    },
    uploadStarted: function(i, file, n) {
      var node = this.addNode(file).data('file', file);
      node.find('.trash').hide();
      this.uploads.push(node);
      this.progressUpdated(i, file, 0);
    },
    uploadFinished: function(i, file, response, time, xhr) {
      var that = this;
      this.uploads.some(function(e) {
        if (e.data('file') == file) {
          e.find('[name="'+that.options.name+'"]').val(response);
          e.find('.progress-bar')
            .width('100%')
            .attr({'aria-valuenow': 100})
          e.find('.trash').show();
          setTimeout(function() { e.find('.progress').hide(); }, 600);
          return true;
        }
      });
    },
    fileSize: function(size) {
      var sizes = ['k', 'M', 'G', 'T'],
          suffix = '';
      while (size > 900) {
        size /= 1024;
        suffix = sizes.shift();
      }
      return size.toPrecision(3) + suffix + 'B';
    },
    addNode: function(file) {
      var filenode = $('<div class="file"></div>')
          .append($('<div class="filetype"></div>').addClass())
          .append($('<div class="filename"></div>').text(file.name)
            .append($('<span class="filesize"></span>').text(
              this.fileSize(file.size)
            )).append($('<div class="upload-rate pull-right"></div>'))
          ).append($('<div class="progress"></div>')
            .append($('<div class="progress-bar"></div>'))
            .attr({'aria-valuemin':0,'aria-valuemax':100})
            .hide())
          .append($('<input type="hidden"/>').attr('name', this.options.name)
            .val(file.id));
      if (this.options.deletable) {
        filenode.prepend($('<span><i class="icon-trash"></i></span>')
          .addClass('trash pull-right')
          .click($.proxy(this.deleteNode, this, filenode))
        );
      }
      this.$element.parent().find('.files').append(filenode);
      return filenode;
    },
    deleteNode: function(filenode, e) {
      if (confirm(__('You sure?')))
        filenode.remove();
    }
  };

  $.fn.filedropbox = function ( option ) { 
    return this.each(function () {
      var $this = $(this),
        data = $this.data('dropbox'),
        options = typeof option == 'object' && option;
      if (!data) $this.data('dropbox', (data = new FileDropbox(this, options)));
      if (typeof option == 'string') data[option]();
    }); 
  }; 

  $.fn.filedropbox.defaults = {
    files: [],
    deletable: true
  };

  $.fn.filedropbox.Constructor = FileDropbox;
  
}(jQuery);
